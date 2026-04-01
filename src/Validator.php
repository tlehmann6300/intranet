<?php

/**
 * Validator
 *
 * Centralised, immutable input-validation helper.
 *
 * Usage example:
 *   $v = Validator::make($_POST, [
 *       'email'       => 'required|email|max:255',
 *       'amount'      => 'required|numeric|min:0.01',
 *       'description' => 'required|string|max:1000',
 *   ]);
 *
 *   if ($v->fails()) {
 *       // $v->errors() returns ['field' => 'first error message', …]
 *   }
 */
class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,string> */
    private array $errors = [];

    /**
     * @param array<string,mixed>         $data  Raw input (e.g. $_POST / $_GET)
     * @param array<string,string|array>  $rules Field → pipe-separated rule string or array of rules
     */
    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->validate($rules);
    }

    // ------------------------------------------------------------------
    // Factory
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>        $data
     * @param array<string,string|array> $rules
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return empty($this->errors) ? null : reset($this->errors);
    }

    /**
     * Return a sanitised, typed value from the validated data.
     * Returns null if the field is missing or empty.
     *
     * @return mixed
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    /**
     * Return only the listed fields from the input (validated subset).
     *
     * @param  string[] $fields
     * @return array<string,mixed>
     */
    public function only(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->data)) {
                $result[$field] = $this->data[$field];
            }
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /** @param array<string,string|array> $rules */
    private function validate(array $rules): void
    {
        foreach ($rules as $field => $ruleSet) {
            $ruleList = is_array($ruleSet)
                ? $ruleSet
                : array_filter(array_map('trim', explode('|', $ruleSet)));

            $value    = $this->data[$field] ?? null;
            $isString = is_string($value);
            $trimmed  = $isString ? trim($value) : $value;

            foreach ($ruleList as $rule) {
                [$ruleName, $param] = $this->parseRule($rule);

                $error = $this->applyRule($field, $trimmed, $ruleName, $param);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break; // Stop after first failure per field
                }
            }
        }
    }

    /** @return array{string,string|null} */
    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $param] = explode(':', $rule, 2);
            return [trim($name), trim($param)];
        }
        return [$rule, null];
    }

    /** @return string|null Error message or null if valid */
    private function applyRule(string $field, mixed $value, string $rule, ?string $param): ?string
    {
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    return "Das Feld '$field' ist erforderlich.";
                }
                break;

            case 'string':
                if ($value !== null && $value !== '' && !is_string($value)) {
                    return "Das Feld '$field' muss ein Text sein.";
                }
                break;

            case 'numeric':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    return "Das Feld '$field' muss eine Zahl sein.";
                }
                break;

            case 'integer':
                if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    return "Das Feld '$field' muss eine ganze Zahl sein.";
                }
                break;

            case 'email':
                if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return "Das Feld '$field' muss eine gültige E-Mail-Adresse sein.";
                }
                break;

            case 'url':
                if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                    return "Das Feld '$field' muss eine gültige URL sein.";
                }
                break;

            case 'min':
                if ($param === null) {
                    break;
                }
                if (is_numeric($value) && (float) $value < (float) $param) {
                    return "Das Feld '$field' muss mindestens $param sein.";
                }
                if (is_string($value) && mb_strlen($value) < (int) $param) {
                    return "Das Feld '$field' muss mindestens $param Zeichen lang sein.";
                }
                break;

            case 'max':
                if ($param === null) {
                    break;
                }
                if (is_numeric($value) && (float) $value > (float) $param) {
                    return "Das Feld '$field' darf höchstens $param sein.";
                }
                if (is_string($value) && mb_strlen($value) > (int) $param) {
                    return "Das Feld '$field' darf höchstens $param Zeichen lang sein.";
                }
                break;

            case 'in':
                if ($param !== null && $value !== null && $value !== '') {
                    $allowed = array_map('trim', explode(',', $param));
                    if (!in_array((string) $value, $allowed, true)) {
                        return "Das Feld '$field' enthält einen ungültigen Wert.";
                    }
                }
                break;

            case 'not_in':
                if ($param !== null && $value !== null && $value !== '') {
                    $forbidden = array_map('trim', explode(',', $param));
                    if (in_array((string) $value, $forbidden, true)) {
                        return "Das Feld '$field' enthält einen unzulässigen Wert.";
                    }
                }
                break;

            case 'regex':
                if ($param !== null && $value !== null && $value !== '') {
                    if (preg_match($param, (string) $value) !== 1) {
                        return "Das Feld '$field' hat ein ungültiges Format.";
                    }
                }
                break;

            case 'date':
                if ($value !== null && $value !== '') {
                    $d = date_create((string) $value);
                    if ($d === false) {
                        return "Das Feld '$field' muss ein gültiges Datum sein.";
                    }
                }
                break;

            case 'boolean':
                if ($value !== null && !in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                    return "Das Feld '$field' muss ein boolescher Wert sein.";
                }
                break;

            case 'array':
                if ($value !== null && !is_array($value)) {
                    return "Das Feld '$field' muss ein Array sein.";
                }
                break;

            case 'nullable':
                // Always passes – marks field as explicitly optional
                break;

            default:
                // Unknown rule – silently skip
                break;
        }

        return null;
    }
}
