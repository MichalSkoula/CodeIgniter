<?php

defined('BASEPATH') OR define('BASEPATH', dirname(__DIR__).'/system/');
require_once dirname(__DIR__).'/system/libraries/Driver.php';
require_once dirname(__DIR__).'/system/helpers/file_helper.php';
require_once dirname(__DIR__).'/system/libraries/Cache/drivers/Cache_file.php';

final class Cache_file_test extends \PHPUnit\Framework\TestCase
{

	private string $directory;

	private CI_Cache_file $cache;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir().'/ci-file-cache-test-'.bin2hex(random_bytes(8));
		mkdir($this->directory, 0700);
		$reflection = new ReflectionClass('CI_Cache_file');
		$this->cache = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('_cache_path')->setValue($this->cache, $this->directory.'/');
		set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
			if (error_reporting() & $severity)
			{
				throw new ErrorException($message, 0, $severity, $file, $line);
			}
			return TRUE;
		});
	}

	protected function tearDown(): void
	{
		restore_error_handler();
		foreach (glob($this->directory.'/*') as $file)
		{
			unlink($file);
		}
		rmdir($this->directory);
	}

	public function test_valid_cache_preserves_arrays_objects_and_metadata(): void
	{
		$value = array('products' => array((object) array('id' => 42)), 'quantity' => 3);
		self::assertTrue($this->cache->save('order', $value, 12));
		self::assertEquals($value, $this->cache->get('order'));
		self::assertArrayHasKey('expire', $this->cache->get_metadata('order'));
		self::assertFalse($this->cache->get('missing'));
	}

	public function test_external_deletion_is_seen_after_a_successful_cached_stat(): void
	{
		self::assertTrue($this->cache->save('order', 'working', 12));
		self::assertSame('working', $this->cache->get('order'));
		$file = $this->directory.'/order';
		self::assertTrue(is_file($file));

		// Deletion by another process does not clear this process's stat cache.
		$process = proc_open(array(PHP_BINARY, '-r', 'exit(unlink($argv[1]) ? 0 : 1);', $file), array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w')
		), $pipes);
		self::assertIsResource($process);
		foreach ($pipes as $pipe)
		{
			fclose($pipe);
		}
		self::assertSame(0, proc_close($process));
		self::assertTrue(is_file($file));
		self::assertFalse($this->cache->get('order'));
	}

	public function test_read_during_a_truncated_write_returns_a_miss_and_can_recover(): void
	{
		self::assertTrue($this->cache->save('order', 'working', 12));
		$writer = fopen($this->directory.'/order', 'wb');
		self::assertIsResource($writer);
		self::assertTrue(flock($writer, LOCK_EX));
		try
		{
			self::assertFalse($this->cache->get('order'));
		}
		finally
		{
			flock($writer, LOCK_UN);
			fclose($writer);
		}
		self::assertTrue($this->cache->save('order', 'completed write', 12));
		self::assertSame('completed write', $this->cache->get('order'));
	}

	public function test_file_disappearing_between_stat_and_read_returns_a_miss(): void
	{
		self::assertTrue($this->cache->save('order', 'working', 12));
		Cache_file_disappearing_stream::$file = $this->directory.'/order';
		Cache_file_disappearing_stream::$read_attempted = FALSE;
		self::assertTrue(stream_wrapper_register('ci-cache-race', Cache_file_disappearing_stream::class));
		(new ReflectionClass('CI_Cache_file'))->getProperty('_cache_path')->setValue($this->cache, 'ci-cache-race://');
		try
		{
			self::assertFalse($this->cache->get('order'));
			self::assertTrue(Cache_file_disappearing_stream::$read_attempted);
		}
		finally
		{
			stream_wrapper_unregister('ci-cache-race');
		}
	}

	public function test_invalid_cache_is_a_miss_without_deleting_a_file_that_may_be_rewritten(): void
	{
		foreach (array('', 'invalid', 'b:0;', 'N;', serialize(array('time' => time(), 'ttl' => 12)), serialize(array('time' => time(), 'ttl' => array(), 'data' => 'working'))) as $contents)
		{
			file_put_contents($this->directory.'/order', $contents);
			self::assertFalse($this->cache->get('order'));
			self::assertFileExists($this->directory.'/order');
		}
	}

	public function test_expiration_and_zero_ttl_keep_existing_behavior(): void
	{
		file_put_contents($this->directory.'/order', serialize(array('time' => time() - 120, 'ttl' => 12, 'data' => 'expired')));
		self::assertFalse($this->cache->get('order'));
		self::assertFileDoesNotExist($this->directory.'/order');
		file_put_contents($this->directory.'/order', serialize(array('time' => time() - 120, 'ttl' => 0, 'data' => 'persistent')));
		self::assertSame('persistent', $this->cache->get('order'));
	}
}

final class Cache_file_disappearing_stream
{

	public mixed $context;

	public static string $file;

	public static bool $read_attempted;

	public function url_stat(string $path, int $flags): array
	{
		$stat = stat(self::$file);
		unlink(self::$file);
		return $stat;
	}

	public function stream_open(string $path, string $mode, int $options, mixed &$opened_path): bool
	{
		self::$read_attempted = TRUE;
		return FALSE;
	}
}
