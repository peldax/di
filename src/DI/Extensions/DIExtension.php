<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI\Extensions;

use Nette;


/**
 * DI extension.
 */
final class DIExtension extends Nette\DI\CompilerExtension
{
	/** @var string[] */
	public $exportedTags = [];

	/** @var string[] */
	public $exportedTypes = [];

	/** @var bool */
	private $debugMode;

	/** @var int */
	private $time;


	public function __construct(bool $debugMode = false)
	{
		$this->debugMode = $debugMode;
		$this->time = microtime(true);

		$this->config = new class {
			/** @var bool */
			public $debugger;
			/** @var string[] */
			public $excluded = [];
			/** @var ?string */
			public $parentClass;
			/** @var object */
			public $export;
		};
		$this->config->export = new class {
			/** @var bool */
			public $parameters = true;
			/** @var string[]|bool|null */
			public $tags = true;
			/** @var string[]|bool|null */
			public $types = true;
		};
		$this->config->debugger = interface_exists(\Tracy\IBarPanel::class);
	}


	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$builder->addExcludedClasses($this->config->excluded);
	}


	public function afterCompile(Nette\PhpGenerator\ClassType $class)
	{
		if ($this->config->parentClass) {
			$class->setExtends($this->config->parentClass);
		}

		$this->exportParameters($class);
		$this->exportTags($class);
		$this->exportTypes($class);

		if ($this->debugMode && $this->config->debugger) {
			$this->enableTracyIntegration($class);
		}

		$this->initializeTaggedServices($class);
	}


	private function exportParameters(Nette\PhpGenerator\ClassType $class): void
	{
		if (!$this->config->export->parameters) {
			$class->getMethod('__construct')
				->addBody('parent::__construct($params);');
		}
	}


	private function exportTags(Nette\PhpGenerator\ClassType $class): void
	{
		$option = $this->config->export->tags;
		if (!$option) {
			$class->removeProperty('tags');
		} elseif (is_array($option) && isset($class->getProperties()['tags'])) {
			$prop = $class->getProperty('tags');
			$prop->value = array_intersect_key($prop->value, $this->exportedTags + array_flip($option));
		}
	}


	private function exportTypes(Nette\PhpGenerator\ClassType $class): void
	{
		$option = $this->config->export->types;
		$exported = $option === true ? null : $this->exportedTypes + array_flip(is_array($option) ? $option : []);
		$data = $this->getContainerBuilder()->exportTypes($exported);
		$class->addProperty('wiring')->setVisibility('protected')->setValue($data);
	}


	private function initializeTaggedServices(Nette\PhpGenerator\ClassType $class): void
	{
		foreach (array_filter($this->getContainerBuilder()->findByTag('run')) as $name => $on) {
			trigger_error("Tag 'run' used in service '$name' definition is deprecated.", E_USER_DEPRECATED);
			$class->getMethod('initialize')->addBody('$this->getService(?);', [$name]);
		}
	}


	private function enableTracyIntegration(Nette\PhpGenerator\ClassType $class): void
	{
		Nette\Bridges\DITracy\ContainerPanel::$compilationTime = $this->time;
		$class->getMethod('initialize')->addBody($this->getContainerBuilder()->formatPhp('?;', [
			new Nette\DI\Definitions\Statement('@Tracy\Bar::addPanel', [new Nette\DI\Definitions\Statement(Nette\Bridges\DITracy\ContainerPanel::class)]),
		]));
	}
}
