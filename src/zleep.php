<?php

declare(strict_types=1);

namespace SOFe\Zleep;

use Closure;
use Generator;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\ReversePriorityQueue;
use SOFe\AwaitGenerator\Await;
use SplPriorityQueue;
use function is_finite;
use function max;
use function microtime;

final class Zleep {
	/**
	 * Sleep for the specified number of ticks.
	 */
	public static function sleepTicks(Plugin $plugin, int $ticks) : Generator {
		/** @var ?TaskHandler $handler */
		$handler = null;
		try {
			yield from Await::promise(function($resolve) use ($plugin, $ticks, &$handler) {
				$handler = $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask($resolve), $ticks);
			});
			$handler = null;
		} finally {
			if ($handler !== null) {
				$handler->cancel();
			}
		}
	}

	/**
	 * Sleep until $seconds seconds have passed
	 */
	public static function sleepSeconds(Plugin $plugin, float $seconds) : Generator {
		yield from self::sleepUntilTimestamp($plugin, microtime(true) + $seconds);
	}

	/**
	 * Sleep until the given timestamp.
	 */
	public static function sleepUntilTimestamp(Plugin $plugin, float $target) : Generator {
		$heap = self::$clockLoop ?? new TimestampHeap;
		$promise = Await::promise(fn($resolve) => $heap->insert($target, $resolve));
		if (self::$clockLoop === null) {
			Await::g2c(self::runClockLoop($plugin, $heap));
		}
		yield from $promise;
	}

	private static ?TimestampHeap $clockLoop = null;
	private static function runClockLoop(Plugin $plugin, TimestampHeap $heap) : Generator {
		self::$clockLoop = $heap;

		while (is_finite($rem = $heap->getRemaining())) {
			if ($rem >= 0.05) { // more than one tick
				yield from self::sleepTicks($plugin, 1);
				continue;
			}

			$closure = $heap->shift();
			if ($closure !== null) {
				$closure();
			}
		}

		self::$clockLoop = null;
	}
}

/**
 * @internal
 */
final class TimestampHeap {
	/** @var ReversePriorityQueue<float, Closure(): void> */
	private ReversePriorityQueue $queue;

	public function __construct() {
		$this->queue = new ReversePriorityQueue;
	}

	/**
	 * @param Closure(): void $callback
	 */
	public function insert(float $target, Closure $callback) : void {
		$this->queue->insert($callback, $target);
	}

	public function getRemaining() : float {
		if ($this->queue->isEmpty()) {
			return INF;
		}

		$this->queue->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
		/** @var float $ts */
		$ts = $this->queue->top();
		return max(0.0, $ts - microtime(true));
	}

	/**
	 * @return Closure(): void
	 */
	public function shift() : ?Closure {
		if ($this->queue->isEmpty()) {
			return null;
		}

		$this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
		/** @var Closure(): void $extract */
		$extract = $this->queue->extract();
		return $extract;
	}
}
