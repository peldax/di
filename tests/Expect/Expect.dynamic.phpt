<?php

declare(strict_types=1);

use Nette\DI\Config\Expect;
use Nette\DI\Definitions\DynamicParameter;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


test(function () {
	$expectation = Expect::structure([
		'a' => Expect::string()->dynamic(),
		'b' => Expect::string('def')->dynamic(),
		'c' => Expect::int()->dynamic(),
		'arr' => Expect::arrayOf(Expect::int()->dynamic()),
		'enum' => Expect::enum(Expect::int(), Expect::string())->dynamic(),
	]);

	Assert::equal(
		(object) [
			'a' => new DynamicParameter("\$this->parameters['foo']"),
			'b' => new DynamicParameter("\$this->parameters['bar']"),
			'c' => 123,
			'arr' => ['x' => new DynamicParameter("\$this->parameters['baz']")],
			'enum' => new DynamicParameter("\$this->parameters['enum']"),
		],
		$expectation->complete([
			'a' => new DynamicParameter("\$this->parameters['foo']"),
			'b' => new DynamicParameter("\$this->parameters['bar']"),
			'c' => 123,
			'arr' => ['x' => new DynamicParameter("\$this->parameters['baz']")],
			'enum' => new DynamicParameter("\$this->parameters['enum']"),
		])
	);
});
