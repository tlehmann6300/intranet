<?php
if (!class_exists('NoDiscard')) { #[\Attribute(\Attribute::TARGET_FUNCTION|\Attribute::TARGET_METHOD)] class NoDiscard { public function __construct(public readonly string $message = '') {} } }
