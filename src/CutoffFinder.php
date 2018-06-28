<?php

/**
 *
 */
class CutoffFinder{

	public $cutOff = 0.05;
	public $minCutoff;
	public $previousCutoff = null;
	public $hitRate;

	/**
	 * [__construct description]
	 */
	public function __construct()
	{
		$this->minCutoff = $this->cutOff;
	}

	/**
	 * [getCutoff description]
	 * @return [type] [description]
	 */
	public function getCutoff(){
		return $this->cutOff;
	}

	/**
	 * [setCutoff description]
	 * @param [type] $cutOff [description]
	 */
	public function setCutoff($cutOff){
		$this->previousCutoff = $this->cutOff;
		$this->cutOff = $cutOff;
	}

	/**
	 * [setHitRate description]
	 * @param [type] $hitRate [description]
	 */
	public function setHitRate($hitRate){
		$this->hitRate = $hitRate;
	}

	/**
	 * [incrementCutoff description]
	 * @return [type] [description]
	 */
	private function incrementCutoff(){
		$this->previousCutoff = $this->cutOff;
		$this->cutOff = $this->cutOff/2;
		if($this->cutOff > $this->minCutoff){
			//echo $this->cutOff.'===='.$this->minCutoff."\n";
			$this->cutOff = $this->minCutoff;
		}
	}

	/**
	 * [decrementCutoff description]
	 * @return [type] [description]
	 */
	private function decrementCutoff(){
		$this->previousCutoff = $this->cutOff;
		$this->cutOff = $this->cutOff + ($this->cutOff/2);
	}

	/**
	 * [calculateNewCutoff description]
	 * @param  [type] $hitRate [description]
	 * @return [type]          [description]
	 */
	public function calculateNewCutoff($hitRate){
		if($hitRate < $this->hitRate){
			$this->incrementCutoff();
		}elseif($hitRate >= $this->hitRate){
			$this->decrementCutoff();
		}
		$this->hitRate = $hitRate;
	}
}