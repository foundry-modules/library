<?php

class %BOOTCODE%_Stylesheet_Task {

	const MESSAGE_SUCCESS = 'success';
	const MESSAGE_ERROR   = 'error';
	const MESSAGE_INFO    = 'info';
	const MESSAGE_WARN    = 'warn';

	const STATE_SUCCESS = 'success';
	const STATE_ERROR   = 'error';
	const STATE_PENDING = 'pending';

	// Task summary
	public $name;
	public $state;
	public $message = '';
	public $details = array();
	public $failed = false;
	public $subtasks = array();
	public $result;

	// Task profiling
	public $time_start;
	public $time_end;
	public $time_total;
	public $mem_start;
	public $mem_end;
	public $mem_peak;

	// Task reporting
	public $output = null;

	public function __construct($name='Task', $autostart=true) {

		$this->name  = $name;
		$this->state = self::STATE_PENDING;

		if ($autostart) $this->start();
	}

	private function start($message='', $type=self::MESSAGE_INFO) {

		if (empty($message)) $message = "$this->name started.";

		$this->time_start = microtime(true);
		$this->mem_start  = memory_get_usage();
		$this->report($message, $type);

		return $this;
	}

	private function stop($message='', $type=self::MESSAGE_INFO) {

		if (empty($message)) $message = "$this->name stopped.";

		$this->time_end   = microtime(true);
		$this->time_total = $this->time_end - $this->time_start;
		$this->mem_end    = memory_get_usage();
		$this->mem_peak   = memory_get_peak_usage();
		$this->report($message, $type);

		// Write to log file
		$this->save();

		return $this;
	}

	public function resolve($message='', $type=self::MESSAGE_SUCCESS) {

		if (empty($message)) $message = "$this->name completed.";

		$this->message = $message;
		$this->state   = self::STATE_SUCCESS;
		$this->failed  = false;
		$this->stop($message, $type);

		return $this;
	}

	public function reject($message='', $type=self::MESSAGE_ERROR) {

		if (empty($message)) $message = "$this->name failed.";

		$this->message = $message;
		$this->state   = self::STATE_ERROR;
		$this->failed  = true;
		$this->stop($message, $type);

		return $this;
	}

	public function report($message='', $type=self::MESSAGE_WARN) {

		// Strip site root path
		$message = str_ireplace(%BOOTCODE%_FOUNDRY_JOOMLA_PATH . DIRECTORY_SEPARATOR, '', $message);

		$detail = (object) array(
			'timestamp' => time(),
			'message'   => $message,
			'type'      => $type
		);

		$this->details[] = $detail;

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

			$task[$prop] = $this->$prop;

			// Strip site root path
			if ($prop=='message') {
				$task[$prop] = str_ireplace(%BOOTCODE%_FOUNDRY_JOOMLA_PATH . DIRECTORY_SEPARATOR, '', $this->$prop);
			}
		}

		$task['subtasks'] = array();

		foreach($this->subtasks as $subtask) {
			$task['subtasks'][] = $subtask->toArray();
		}

		return $task;
	}

	public function toJSON() {

		return json_encode($this->toArray());
	}

	public function save($output=null) {

		// If no output path given
		if (is_null($output)) {

			// Get output path from instance property
			if (is_null($this->output)) return false;
			$output = $this->output;
		}

		// Export log content
		$content = $this->toJSON();

		return JFile::write($output, $content);
	}
}