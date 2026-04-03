<?php
if (!class_exists('Deprecated')) { #[\Attribute(\Attribute::TARGET_FUNCTION|\Attribute::TARGET_METHOD|\Attribute::TARGET_CLASS_CONSTANT)] class Deprecated { public function __construct(public readonly string $message = '', public readonly string $since = '') {} } }
