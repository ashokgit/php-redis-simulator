<?php

use ashokgit\ZipfDistributionGenerator;
use gburtini\Distributions\Normal;
use gburtini\Distributions\Poisson;
use gburtini\Distributions\Weibull;

require 'CutoffFinder.php';

/**
 *
 */
class RedisPhpSimulator
{
    private $_connection = [
    'host'  => '127.0.0.1',
    'password'  => 'localworld',
    'port'  =>  6379,
    'maxmemory' => '1MB'
    ];

    public $redisInfo;
    public $timeToLive = 30; //in seconds , low for testing purposes
    public $keyMetrics = [];
    public $settingMetrics = [];
    public $executionTime = [];

    public $testDataPath = __DIR__ . '/../data/pages/';
    public $testData;

    public $activeDistribution;

    public $recordIntervall = 50;
    public $noOfPages = 100;
    public $maxPageHitPerPage = 100;
    public $resultPath = __DIR__ . '/../data/results/';
    public $simulationName = "DefaultTestCase";
    public $headerAdded = false;

    private $redis;
    public $distributions = ["zipf" , "poisson", "weibull", "normal"];
    public $policies = ["volatile-lru", "allkeys-lru", "allkeys-random", "volatile-random", "volatile-ttl", "noeviction"];
    private $defaultPolicyIndex = 2;
    private $distributionDataSet;

    //filter things
    private $cutoffFinder;
    private $bestPolicy = false;
    private $applyFilter = false;
    private $filterName = false;
    public $hitRateWithoutFilter = 0;
    public $hitratePerAlgo = [];

    public $enableLoggingToConsole = false;

    /**
     * [__construct description]
     */
    public function __construct($redisParams=false)
    {
        if($redisParams && is_array($redisParams)){
            $this->setConnectionParams($redisParams);
        }
        $this->cutoffFinder = new CutoffFinder;
        $this->removePreviousTestResults();
    }

    /**
     * [setConnectionParams description]
     * @param [type] $connection [description]
     */
    public function setConnectionParams($connection)
    {
        if(isset($connection['host'])){
            $this->_connection['host'] = $connection['host'];
        }

        if(isset($connection['password'])){
            $this->_connection['password'] = $connection['password'];
        }

        if(isset($connection['port'])){
            $this->_connection['port'] = $connection['port'];
        }

        if(isset($connection['maxmemory'])){
            $this->_connection['maxmemory'] = $connection['maxmemory'];
        }

        return TRUE;
    }

    /**
     * [setConfigurations description]
     */
    private function setConfigurations(){
        try {
            $this->redis->config("SET", "maxmemory", $this->_connection['maxmemory']);
            return TRUE;
        } catch (Exception $e) {
            throw new Exception("Could not set configurations.".$e->getMessage());
        }
    }

    /**
     * [connect description]
     * @return [type] [description]
     */
    public function connect()
    {
        try {
            $redis = new Redis();
            $redis->pconnect($this->_connection['host'], $this->_connection['port']);
            $redis->auth($this->_connection['password']);
            $this->redis = $redis;
            $this->log("Redis Connected");
            //set redis configurations
            $this->setConfigurations();
            return TRUE;
        }catch(Exception $e) {
            $this->log("Redis Could not be Connected");
            throw new Exception("Redis Could not be Connected ".$e->getMessage());
        }
    }

    public function simulate(){
        $this->runThroughDistributions();
        $this->filterName = 'Threshold';
        $this->runThroughDistributions();
        $this->filterName = 'Chance';
        $this->runThroughDistributions();
    }

    /**
     * [runThroughDistributions description]
     * @return [type] [description]
     */
    public function runThroughDistributions()
    {
        foreach ($this->distributions as $distribution) {
            $this->activeDistribution = $distribution;
            $this->distributionDataSet = $this->getDistribution($this->activeDistribution);
            if(!$this->filterName){
                $this->runThroughPoliciesWithoutFilter();
            }
            else{
                if(!$this->bestPolicy){
                    $this->findBestPolicy();
                }
                $this->policies = [$this->bestPolicy];
                $this->runThroughPoliciesWithFilter();
            }
        }
        $this->makeResult(false);
    }

    /**
     * [runThroughPolicies description]
     * @return [type] [description]
     */
    public function runThroughPoliciesWithoutFilter(){
        foreach ($this->policies as $key => $value) {
            $this->defaultPolicyIndex = $key;
            $this->run();

            $this->cutoffFinder->setHitRate($this->keyMetrics['hitRate']);
            $this->hitRateWithoutFilter = $this->keyMetrics['hitRate'];

            $calc = round((($this->keyMetrics['hitRate'] - $this->hitRateWithoutFilter)/$this->hitRateWithoutFilter) * 100);

            $this->hitratePerAlgo[$this->activeDistribution][$this->policies[$this->defaultPolicyIndex]]['WithOutFilter'] = $this->hitRateWithoutFilter;
        }
    }

    /**
     * [findBestPolicy description]
     * @return [type] [description]
     */
    private function findBestPolicy(){
        $bestPolicy = '';
        $maxHitRate = 0;
        foreach($this->hitratePerAlgo as $distributions){
            foreach ($distributions as $policy => $value) {
                if($value['WithOutFilter']>$maxHitRate){
                    $bestPolicy = $policy;
                    $maxHitRate = $value['WithOutFilter'];
                }
            }
        }
        echo "Best Policy for this distribution: ".$bestPolicy."\n";

        $this->bestPolicy = $bestPolicy;
    }


    /**
     * [runThroughPolicies description]
     * @return [type] [description]
     */
    public function runThroughPoliciesWithFilter(){
        $this->applyFilter = true;
        $decimalPoints = 4;
        $breakAfterXGains = 5;
        $i=0;
        foreach ($this->policies as $key => $value) {
            $this->defaultPolicyIndex = $key;
            $hitRateWithoutFilter = $this->hitratePerAlgo[$this->activeDistribution][$this->policies[$this->defaultPolicyIndex]]['WithOutFilter'];

            $maxHitRate = 0;
            $maxGainCutoff = 0;
            do{
                $this->run();
                $this->makeResult(false);
                $this->cutoffFinder->calculateNewCutoff($this->keyMetrics['hitRate']);

                if($this->keyMetrics['hitRate'] > $maxHitRate){
                    $maxHitRate = $this->keyMetrics['hitRate'];
                    $maxGainCutoff = $this->cutoffFinder->cutOff;
                    $this->cutoffFinder->minCutoff = $maxGainCutoff;
                    $this->makeResult(true);
                }


                if($this->keyMetrics['hitRate'] > $hitRateWithoutFilter){
                    $i++;
                    if($i>$breakAfterXGains){
                        break;
                    }
                }

            }while(round($this->cutoffFinder->previousCutoff,$decimalPoints) <> round($this->cutoffFinder->cutOff,$decimalPoints));

            $calc = (($maxHitRate - $hitRateWithoutFilter)/$hitRateWithoutFilter) * 100;

            $this->hitratePerAlgo[$this->activeDistribution][$this->policies[$this->defaultPolicyIndex]]['WithFilter'] = $maxHitRate;
            $this->hitratePerAlgo[$this->activeDistribution][$this->policies[$this->defaultPolicyIndex]]['CutOff'] = round($maxGainCutoff,$decimalPoints);
            $this->hitratePerAlgo[$this->activeDistribution][$this->policies[$this->defaultPolicyIndex]]['GainPercentage'] = $calc;
        }
    }

    /**
     * [run description]
     * @return [type] [description]
     */
    public function run()
    {
        $this->headerAdded = false;
        $this->flushDb();
        $this->redis->config("SET", "maxmemory-policy", $this->policies[$this->defaultPolicyIndex]);
        $currentPolicy = $this->redis->config("GET", "maxmemory-policy");
        if ($currentPolicy['maxmemory-policy'] != $this->policies[$this->defaultPolicyIndex]) {
            die("Policy could not be changed. Something went wrong");
        }
        $this->simulatePageLoader();
        $this->saveSettingMetrics();
    }

    /**
     * [simulatePageLoader description]
     * @return [type] [description]
     */
    public function simulatePageLoader()
    {
        $distribution = $this->distributionDataSet;
        //save this distribution to file
        $this->saveDistribution($distribution);

        $distributionSelectorData = $this->makeDistributionSelectorData($distribution);
        //record this key metric
        $this->settingMetrics['totalUserHits'] = $distributionSelectorData['total'];

        $this->recordProcessStartTime($this->policies[$this->defaultPolicyIndex]);
        for ($i = 0; $i < $distributionSelectorData['total']; $i++) {

            $cacheKey = $this->biasedRandom($distributionSelectorData);
            //cacheKey is the page id i.e the name of the test data
            //first try to get this key
            $cachedData = $this->redis->get($cacheKey);
            if (!$cachedData) {
                $this->loadTestData($cacheKey . '.json');
                $cachedData = $this->testData;
                try {
                    if($this->filterName=='Chance'){
                        if ($this->isValueCachableChance($distribution, $cacheKey)) {
                            $this->redis->set($cacheKey, $cachedData, $this->timeToLive);
                        }
                    }else{
                        if ($this->isValueCachableThreshold($distribution, $cacheKey)) {
                            $this->redis->set($cacheKey, $cachedData, $this->timeToLive);
                        }
                    }
                } catch (Exception $e) {
                    echo 'Redis error message: ' . $e->getMessage();
                }

            }
            $this->recordProcessEndTime($this->policies[$this->defaultPolicyIndex]);
            $this->settingMetrics['totalUserHits'] = $distributionSelectorData['total'];
            $this->keyMetrics['ProcessedItems'] = $i;
            //$this->keyMetrics['key'] = $cacheKey;
            $divider = round($distributionSelectorData['total'] / $this->recordIntervall);
            if (($i == 1) || ($i > 0 && $i % $divider == 0)) {
                if($this->applyFilter){
                   $this->keyMetrics['cutOff'] = $this->cutoffFinder->cutOff;
               }

               $this->makeResult(!$this->applyFilter);
           }
       }
   }

    /**
     * [isValueCachable description]
     * @param  [type]  $distribution [description]
     * @param  [type]  $cacheKey     [description]
     * @return boolean               [description]
     */
    public function isValueCachableThreshold($distribution, $cacheKey)
    {
        if(!$this->applyFilter) return true;

        $highestHits = max($distribution);
        $ratio = $distribution[$cacheKey] / $highestHits;

        if ($ratio >= $this->cutoffFinder->cutOff) {
            return true;
        }
        return false;
    }

    public function isValueCachableChance($distribution, $cacheKey){
        if(!$this->applyFilter) return true;

        $highestHits = max($distribution);
        $ratio = $distribution[$cacheKey] / $highestHits;
        $cPS = exp(-$this->cutoffFinder->cutOff/$ratio);
        //echo round($cPS)."\n";//$this->cutoffFinder->cutOff." -- ".$ratio." -- ".$cPS."\n";

        if (round($cPS)==1) {
            return true;
        }
        return false;
    }

    /**
     * [loadTestData description]
     * @param  [type] $page [description]
     * @return [type]       [description]
     */
    public function loadTestData($page)
    {
        $data = file_get_contents($this->testDataPath . $page);
        $jsonArray = json_decode($data, true);
        $this->testData = $jsonArray;
    }

    /**
     * [flushDb description]
     * @return [type] [description]
     */
    public function flushDb()
    {
        $this->redis->flushDb();
        shell_exec('redis-cli -h ' . $this->_connection['host'] . ' -p ' . $this->_connection['port'] . ' -a ' . $this->_connection['password'] . ' CONFIG RESETSTAT');
        if ($this->redis->dbSize() > 0) {
            die("DB NOT FLUSHED");
        }
        $this->log("Flushed Data and reset stats");
    }

    /**
     * [getMetrics description]
     * @return [type] [description]
     */
    public function getMetrics()
    {
        $this->redisInfo = $this->redis->info();
    }

    /**
     * [getKeyMetrics description]
     * @return [type] [description]
     */
    public function getKeyMetrics()
    {
        //read all on https://www.datadoghq.com/blog/how-to-monitor-redis-performance-metrics/#performance-metrics
        //This is almost always equals to zero
        $this->settingMetrics['rejected_connections'] = $this->redisInfo['rejected_connections'];
        //==================================Performance=============================================
        //Latency is the measurement of the time between a client request and the actual server response.
        //$this->keyMetrics['latency'] = $this->redisInfo['latency'];
        //the number of commands processed per second—if it remains nearly constant, the cause is not a computationally intensive command. If one or more slow commands are causing the latency issues you would see your number of commands per second drop or stall completely
        $this->keyMetrics['instantaneous_ops_per_sec'] = $this->redisInfo['instantaneous_ops_per_sec'];
        //When using Redis as a cache, monitoring the cache hit rate can tell you if your cache is being used effectively or not. A low hit rate means that clients are looking for keys that no longer exist.
        $this->keyMetrics['keyspace_hits'] = $this->redisInfo['keyspace_hits'];
        $this->keyMetrics['keyspace_misses'] = $this->redisInfo['keyspace_misses'];
        $hitsPlusMisses = $this->redisInfo['keyspace_hits'] + $this->redisInfo['keyspace_misses'];
        $this->keyMetrics['hitRate'] = $hitsPlusMisses > 0 ? $this->redisInfo['keyspace_hits'] / $hitsPlusMisses : 0;
        //================================Memory metrics=============================================
        $this->settingMetrics['maxmemory'] = $this->redisInfo['maxmemory'];
        //If used_memory exceeds the total available system memory, the operating system will begin swapping old/unused sections of memory. Every swapped section is written to disk, severely affecting performance. Writing or reading from disk is up to 5 orders of magnitude (100,000x!) slower than writing or reading from memory (0.1 µs for memory vs. 10 ms for disk).
        $this->keyMetrics['used_memory'] = $this->redisInfo['used_memory'];
        $this->settingMetrics['used_memory'] = $this->redisInfo['used_memory'];
        //Tracking key eviction is important because Redis processes each operation sequentially, meaning that evicting a large number of keys can lead to lower hit rates and thus longer latency times.
        $this->keyMetrics['evicted_keys'] = $this->redisInfo['evicted_keys'];
        //An increase in the number of blocked clients waiting on data could be a sign of trouble. Latency or other issues could be preventing the source list from being filled.
        $this->settingMetrics['blocked_clients'] = $this->redisInfo['blocked_clients'];
        //The mem_fragmentation_ratio metric gives the ratio of memory used as seen by the operating system to memory allocated by Redis.A fragmentation ratio greater than 1 indicates fragmentation is occurring. A ratio in excess of 1.5 indicates excessive fragmentation, with your Redis instance consuming 150% of the physical memory it requested. A fragmentation ratio below 1 tells you that Redis needs more memory than is available on your system, which leads to swapping. Swapping to disk will cause significant increases in latency
        $this->keyMetrics['MemoryFragmentationRatio'] = $this->redisInfo['used_memory'] > 0 ? $this->redisInfo['used_memory_rss'] / $this->redisInfo['used_memory'] : 0;

        //==================================Basic activity metrics========================================
        //Because access to Redis is usually mediated by an application (users do not generally directly access the database), for most uses, there will be reasonable upper and lower bounds for the number of connected clients. If the number leaves the normal range, this could indicate a problem.
        $this->settingMetrics['connected_clients'] = $this->redisInfo['connected_clients'];
        //If your database is read-heavy, you are probably making use of the master-slave database replication features available in Redis. In this case, monitoring the number of connected slaves is key.
        $this->settingMetrics['connected_slaves'] = $this->redisInfo['connected_slaves'];
        //When using Redis’s replication features, slave instances regularly check in with their master. A long time interval without communication could indicate a problem on your master Redis server, on the slave, or somewhere in between.
        //$this->keyMetrics['master_last_io_seconds_ago'] = $this->redisInfo['master_last_io_seconds_ago'];
        //Keeping track of the number of keys in your database is generally a good idea. As an in-memory data store, the larger the keyspace, the more physical memory Redis requires to ensure optimal performance. Redis will continue to add keys until it reaches the maxmemory limit, at which point it then begins evicting keys at the same rate new ones come in.
        $this->keyMetrics['keyspace'] = $this->redis->dbSize();

        //other metrics
        //$this->keyMetrics['policyName'] = $this->policies[$this->defaultPolicyIndex];
        $this->keyMetrics['totalExecutionTime'] = $this->executionTime[$this->policies[$this->defaultPolicyIndex]]['time_end'] - $this->executionTime[$this->policies[$this->defaultPolicyIndex]]['time_start'];
    }

    /**
     * [biasedRandom description]
     * @param  [type] $input [description]
     * @return [type]        [description]
     */
    private function biasedRandom($input)
    {
        $distribution = $input['distribution'];
        $total = $input['total'];
        $rand = mt_rand(0, $total - 1);
        foreach ($distribution as $number => $weights) {
            if ($rand < $weights) {
                return $number;
            };
        };
    }

    /**
     * [makeDistributionSelectorData description]
     * @param  [type] $input [description]
     * @return [type]        [description]
     */
    private function makeDistributionSelectorData($input)
    {
        $total = 0;
        foreach ($input as $number => $weight) {
            $total = $total + $weight;
            $distribution[$number] = $total;
        };

        return [
        'total' => $total,
        'distribution' => $distribution,
        ];
    }

    /**
     * [getDistribution description]
     * @param  [type] $name [description]
     * @return [type]       [description]
     */
    private function getDistribution($name)
    {
        switch ($name) {
            case 'zipf':
            return $this->getZipfDistribution();
            break;
            case 'poisson':
            return $this->getPoissonDistribution();
            break;
            case 'weibull':
            return $this->getWeibullDistribution();
            break;
            case 'normal':
            return $this->getNormalDistribution();
            break;
            default:
            die('No Distribution Given');
        }
    }

    /**
     * [getZipfDistribution description]
     * @return [type] [description]
     */
    private function getZipfDistribution()
    {
        $zipf = new ZipfDistributionGenerator;
        $zipf->size = 10;
        $zipf->skew = .5;
        $zipf->generate();

        $distribution = [];
        for ($i = 1; $i < $this->noOfPages; $i++) {
            $distribution[$i] = round($zipf->getProbability($i) * $this->maxPageHitPerPage);
        }
        return $distribution;
    }

    /**
     * [getPoissonDistribution description]
     * @return [type] [description]
     */
    private function getPoissonDistribution()
    {
        $lambda = $this->noOfPages;
        $poisson = new Poisson($lambda);
        $pageHits = [];
        for ($i = 1; $i < $this->maxPageHitPerPage; $i++) {
            $pageHits[] = $poisson->rand();
        }
        $distribution = array_count_values($pageHits);
        ksort($distribution);
        $distribution = array_values($distribution);
        return $distribution;
    }

    /**
     * [getWeibullDistribution description]
     * @return [type] [description]
     */
    private function getWeibullDistribution()
    {
        $lambda = $this->noOfPages;
        $shape = $this->maxPageHitPerPage;
        $weibull = new Weibull(1, $lambda);
        $pageHits = [];
        for ($i = 1; $i < $this->maxPageHitPerPage; $i++) {
            $pageHits[] = (int) round($weibull->rand());
        }
        $distribution = array_count_values($pageHits);
        ksort($distribution);
        $distribution = array_values($distribution);
        return $distribution;
    }

    /**
     * [getNormalDistribution description]
     * @return [type] [description]
     */
    private function getNormalDistribution()
    {
        $normal = new Normal(1, 100);
        $pageHits = [];
        for ($i = 1; $i < $this->maxPageHitPerPage; $i++) {
            $pageHits[] = (int) round($normal->rand());
        }
        $distribution = array_count_values($pageHits);
        ksort($distribution);
        $distribution = array_values($distribution);
        return $distribution;
    }

    /**
     * [recordProcessStartTime description]
     * @return [type] [description]
     */
    private function recordProcessStartTime()
    {
        $this->executionTime[$this->policies[$this->defaultPolicyIndex]]['time_start'] = microtime(true);
    }

    /**
     * [recordProcessEndTime description]
     * @param  [type] $name [description]
     * @return [type]       [description]
     */
    private function recordProcessEndTime($name)
    {
        $this->executionTime[$this->policies[$this->defaultPolicyIndex]]['time_end'] = microtime(true);
    }

    /**
     * [removePreviousTestResults description]
     * @return [type] [description]
     */
    private function removePreviousTestResults()
    {
        $folder = $this->resultPath . '/' . $this->simulationName;
        if (is_dir($folder)) {
            $this->delTree($folder);
        }

    }

    /**
     * [delTree description]
     * @param  [type] $dir [description]
     * @return [type]      [description]
     */
    private function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * [makeResult description]
     * @param  boolean $writeToFile [description]
     * @return [type]               [description]
     */
    private function makeResult($writeToFile = true)
    {
        $this->getMetrics();
        $this->getKeyMetrics();
        $folder = $this->resultPath . $this->simulationName . '/keyMetrics/' . $this->activeDistribution . '/';
        if($this->applyFilter){
            $folder .= 'filter'.$this->filterName.'/';
        }else{
            $folder .= 'without/';
        }

        if (!file_exists($folder)) {
            if (!mkdir($folder, 0777, true)) {
                die('Failed to create folders...');
            }
        }

        $file = $folder . $this->noOfPages . 'X' . $this->maxPageHitPerPage . '-' . $this->policies[$this->defaultPolicyIndex] . '.csv';
        if ($writeToFile) {
            $fp = fopen($file, 'a+');
            if ($this->headerAdded == false) {
                if(file_exists($file)){
                    unlink($file);
                    $fp = fopen($file, 'a+');
                }
                fputcsv($fp, array_keys($this->keyMetrics));
                $this->headerAdded = true;
            }
            fputcsv($fp, $this->keyMetrics);
            fclose($fp);
        } else {
            $this->log($this->keyMetrics);
        }
    }

    /**
     * [saveSettingMetrics description]
     * @return [type] [description]
     */
    private function saveSettingMetrics()
    {
        $folder = $this->resultPath . $this->simulationName . '/settingMetrics/' . $this->activeDistribution . '/';
        if (!file_exists($folder)) {
            if (!mkdir($folder, 0777, true)) {
                die('Failed to create folders...');
            }
        }
        $file = $folder . $this->noOfPages . 'X' . $this->maxPageHitPerPage . '-' . $this->policies[$this->defaultPolicyIndex] . '.csv';
        $fp = fopen($file, 'w');
        fputcsv($fp, array_keys($this->settingMetrics));
        fputcsv($fp, $this->settingMetrics);
        fclose($fp);
    }

    /**
     * [saveDistribution description]
     * @param  [type] $distribution [description]
     * @return [type]               [description]
     */
    private function saveDistribution($distribution)
    {
        $folder = $this->resultPath . $this->simulationName . '/distribution/' . $this->activeDistribution . '/';
        if (!file_exists($folder)) {
            if (!mkdir($folder, 0777, true)) {
                die('Failed to create folders...');
            }
        }
        $file = $folder . $this->noOfPages . 'X' . $this->maxPageHitPerPage . '-' . $this->policies[$this->defaultPolicyIndex] . '.csv';
        if(file_exists($file)){
            unlink($file);
        }
        $fp = fopen($file, 'a+');
        fputcsv($fp, ['Page', 'Hits']);
        foreach ($distribution as $key => $value) {
            fputcsv($fp, [$key, $value]);
        }
        fclose($fp);
    }

    /**
     * [log description]
     * @param  [type] $msg [description]
     * @return [type]      [description]
     */
    private function log($msg)
    {
        if(!$this->enableLoggingToConsole)
            return false;

        if (is_array($msg)) {
            print_r($msg);
        } else {
            echo $msg . "\n";
        }
    }
}
