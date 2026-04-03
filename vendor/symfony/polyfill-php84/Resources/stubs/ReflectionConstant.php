<?php
if (!class_exists('ReflectionConstant')) { class ReflectionConstant implements Reflector { public function __construct(string $name) {} public function getName(): string { return ''; } public function __toString(): string { return ''; } public static function export(): string { return ''; } } }
