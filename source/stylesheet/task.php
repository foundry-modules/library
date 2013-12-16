<?php

class %BOOTCODE%_Stylesheet_Task {

	const MESSAGE_SUCCESS = 'success';
	const MESSAGE_ERROR   = 'error';
	const MESSAGE_INFO    = 'info';
	const MESSAGE_WARN    = 'warn';

	const STATE_SUCCESS = 'success';
	const STATE_ERROR   = 'error';
	const STATE_PENDING = 'pending';

	// Compile summary
	public $state;
	public $message = '';
	public $details = array();
	public $failed = false;
	public $subtasks = array();

	// Compile profiling
	public $time_start;
	public $time_end;
	public $time_total;
	public $mem_start;
	public $mem_end;
	public $mem_peak;

	public function __construct() {
		$this->state = self::STATE_PENDING;
		$this->start();
	}

	private function start() {
		$this->time_start = microtime(true);
		$this->mem_start = memory_get_usage();
	}

	private function stop() {
		$this->time_end = microtime(true);
		$this->time_total = $this->time_end - $this->time_start;
		$this->mem_end = memory_get_usage();
		$this->mem_peak = memory_get_peak_usage();
	}

	public function resolve($message='') {
		$this->message = empty($message) ? 'Compile successful!' : JText::_($message);
		$this->state = self::STATE_SUCCESS;
		$this->failed = false;
		$this->stop();
		return $this;
	}

	public function reject($message='') {
		$this->message = empty($message) ? 'Unknown error occured!' : JText::_($message);
		$this->state = $self::STATE_ERROR;
		$this->failed = true;
		$this->stop();
		return $this;
	}

	public function report($message='', $type=self::MESSAGE_WARN) {

		$detail = (object) array(
			'message' => $message;
			'type'    => $type;
		);

		$details[] = $detail;

		return $detail;
	}

	public function toArray() {

		$task = array();

		$props = array(
			'state',
			'message',
			'details',
			'failed',
			'time_start',
			'time_end',
			'time_total',
			'mem_start',
			'mem_end',
			'mem_peak'
		);

		foreach($props as $prop) {
			$task[$prop] = $this[$prop];
		}

		$task->subtask = array();
		foreach($this->subtasks as $subtask) {
			$task->subtask = $subtask->toArray();
		}

		return $task;
	}

	public function toJSON()
	{
		return json_encode($this->toArray());
	}
}