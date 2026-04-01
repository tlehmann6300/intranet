<?php

declare(strict_types=1);

use Rakit\Validation\Validator as RakitValidator;

class Validator
{
    private static RakitValidator $validator;

    public static function make(array $data, array $rules, array $messages = []): \Rakit\Validation\Validation
    {
        if (!isset(self::$validator)) {
            self::$validator = new RakitValidator();
        }
        $validation = self::$validator->make($data, $rules, $messages);
        $validation->validate();
        return $validation;
    }

    public static function validate(array $data, array $rules, array $messages = []): array
    {
        $validation = self::make($data, $rules, $messages);
        if ($validation->fails()) {
            throw new \InvalidArgumentException(
                implode(' ', array_map(
                    fn($errors) => implode(' ', $errors),
                    $validation->errors()->toArray()
                ))
            );
        }
        return $validation->getValidData();
    }
}
