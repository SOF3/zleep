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
		/** @var null|ResolveWrapper $resolveWrapper */
		$resolveWrapper = null;
		try{
			yield from Await::promise(static function($resolve) use($plugin, $target, &$resolveWrapper) : void {
				$resolveWrapper = new ResolveWrapper($resolve);
				$heap = self::$clockLoop ?? new TimestampHeap;
				$heap->insert($target, $resolveWrapper);
				if (self::$clockLoop === null) {
					Await::g2c(self::runClockLoop($plugin, $heap));
				}
			});
		}finally{
			if ($resolveWrapper !== null) {
				$resolveWrapper->cancel();
			}
		}
	}

	private static ?TimestampHeap $clockLoop = null;
	private static function runClockLoop(Plugin $plugin, TimestampHeap $heap) : Generator {
		self::$clockLoop = $heap;

		while (is_finite($rem = $heap->getRemaining())) {
			if ($rem >= 0.05) { // more than one tick
				yield from self::sleepTicks($plugin, 1);
				continue;
			}

			$resolveWrapper = $heap->shift();
			if ($resolveWrapper !== null) {
				$closure = $resolveWrapper->getClosure();
				if ($closure !== null) {
					$closure();
				}
			}
		}

		self::$clockLoop = null;
	}
}

/** @internal */
final class ResolveWrapper{

	/** @param (Closure(): void)|null $closure */
	public function __construct(private ?Closure $closure) {
	}

	/** @return (Closure(): void)|null */
	public function getClosure() : ?Closure {
		return $this->closure;
	}

	public function cancel() : void {
		$this->closure = null;
	}
}

/**
 * @internal
 */
final class TimestampHeap {
	/** @var ReversePriorityQueue<float, ResolveWrapper> */
	private ReversePriorityQueue $queue;

	public function __construct() {
		$this->queue = new ReversePriorityQueue;
	}

	/**
	 * @param ResolveWrapper $resolveWrapper
	 */
	public function insert(float $target, ResolveWrapper $resolveWrapper) : void {
		$this->queue->insert($resolveWrapper, $target);
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
	 * @return null|ResolveWrapper
	 */
	public function shift() : ?ResolveWrapper {
		if ($this->queue->isEmpty()) {
			return null;
		}

		$this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
		/** @var ResolveWrapper $extract */
		$extract = $this->queue->extract();
		return $extract;
	}
}
