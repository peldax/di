<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI\Extensions;

use Nette;
use Nette\DI\DynamicParameter;


/**
 * Parameters.
 */
final class ParametersExtension extends Nette\DI\CompilerExtension
{
	/** @var string[] */
	public $dynamicParams = [];

	/** @var string[][] */
	public $dynamicValidators = [];

	/** @var callable */
	private $refresh;


	public function __construct(callable $refresh)
	{
		$this->refresh = $refresh;
	}


	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$params = $this->config;

		foreach ($this->dynamicParams as $key) {
			$params[$key] = array_key_exists($key, $params)
				? new DynamicParameter(Nette\PhpGenerator\Helpers::format('($this->parameters[?] \?\? ?)', $key, $params[$key]))
				: new DynamicParameter(Nette\PhpGenerator\Helpers::format('$this->parameters[?]', $key));
		}

		$builder->parameters = Nette\DI\Helpers::expand($params, $params, true);
		($this->refresh)();
	}


	public function afterCompile(Nette\PhpGenerator\ClassType $class)
	{
		$builder = $this->getContainerBuilder();
		$cnstr = $class->getMethod('__construct');
		foreach ($this->dynamicValidators as [$param, $expected]) {
			$cnstr->addBody('Nette\Utils\Validators::assert(?, ?, ?);', [$param, $expected, 'dynamic parameter']);
		}
	}
}
