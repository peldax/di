<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI\Extensions;

use Nette;


/**
 * Parameters.
 */
final class ParametersExtension extends Nette\DI\CompilerExtension
{
	/** @var string[] */
	public $dynamicParams = [];

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
				? $builder::literal('($this->parameters[?] \?\? ?)', [$key, $params[$key]])
				: $builder::literal('$this->parameters[?]', [$key]);
		}

		$builder->parameters = Nette\DI\Helpers::expand($params, $params, true);
		($this->refresh)();
	}
}
