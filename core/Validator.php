<?php
/**
 * Input Validation Class
 */

namespace Gym\Core;

class Validator {
    private array $data = [];
    private array $errors = [];
    private array $rules = [];
    
    /**
     * Create validator instance
     */
    public static function make(array $data, array $rules): self {
        $validator = new self();
        $validator->data = $data;
        $validator->rules = $rules;
        $validator->validate();
        return $validator;
    }
    
    /**
     * Validate all rules
     */
    private function validate(): void {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $value = $this->data[$field] ?? null;
            
            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }
    }
    
    /**
     * Apply a single validation rule
     */
    private function applyRule(string $field, $value, string $rule): void {
        // Parse rule with parameters
        $params = [];
        if (strpos($rule, ':') !== false) {
            [$ruleName, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        } else {
            $ruleName = $rule;
        }
        
        $label = ucwords(str_replace('_', ' ', $field));
        
        switch ($ruleName) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    $this->errors[$field][] = "{$label} is required.";
                }
                break;
                
            case 'email':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field][] = "{$label} must be a valid email address.";
                }
                break;
                
            case 'min':
                $min = $params[0] ?? 0;
                if (is_string($value) && strlen($value) < $min) {
                    $this->errors[$field][] = "{$label} must be at least {$min} characters.";
                } elseif (is_numeric($value) && $value < $min) {
                    $this->errors[$field][] = "{$label} must be at least {$min}.";
                }
                break;
                
            case 'max':
                $max = $params[0] ?? 255;
                if (is_string($value) && strlen($value) > $max) {
                    $this->errors[$field][] = "{$label} must not exceed {$max} characters.";
                } elseif (is_numeric($value) && $value > $max) {
                    $this->errors[$field][] = "{$label} must not exceed {$max}.";
                }
                break;
                
            case 'numeric':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    $this->errors[$field][] = "{$label} must be a number.";
                }
                break;
                
            case 'integer':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->errors[$field][] = "{$label} must be a whole number.";
                }
                break;
                
            case 'date':
                if ($value !== null && $value !== '' && !strtotime($value)) {
                    $this->errors[$field][] = "{$label} must be a valid date.";
                }
                break;
                
            case 'phone':
                if ($value !== null && $value !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $value)) {
                    $this->errors[$field][] = "{$label} must be a valid phone number.";
                }
                break;
                
            case 'in':
                $allowed = $params;
                if ($value !== null && $value !== '' && !in_array($value, $allowed)) {
                    $this->errors[$field][] = "{$label} must be one of: " . implode(', ', $allowed) . ".";
                }
                break;
                
            case 'matches':
                $otherField = $params[0] ?? '';
                $otherValue = $this->data[$otherField] ?? null;
                if ($value !== $otherValue) {
                    $this->errors[$field][] = "{$label} does not match.";
                }
                break;
                
            case 'unique':
                if ($value !== null && $value !== '') {
                    $table = $params[0] ?? '';
                    $column = $params[1] ?? $field;
                    $excludeId = $params[2] ?? null;
                    
                    $sql = "SELECT id FROM {$table} WHERE {$column} = ?";
                    $bindParams = [$value];
                    if ($excludeId) {
                        $sql .= " AND id != ?";
                        $bindParams[] = $excludeId;
                    }
                    $existing = Database::fetchOne($sql, $bindParams);
                    if ($existing) {
                        $this->errors[$field][] = "{$label} is already taken.";
                    }
                }
                break;
                
            case 'url':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->errors[$field][] = "{$label} must be a valid URL.";
                }
                break;
        }
    }
    
    /**
     * Check if validation passed
     */
    public function passes(): bool {
        return empty($this->errors);
    }
    
    /**
     * Check if validation failed
     */
    public function fails(): bool {
        return !empty($this->errors);
    }
    
    /**
     * Get all errors
     */
    public function errors(): array {
        return $this->errors;
    }
    
    /**
     * Get first error
     */
    public function firstError(): ?string {
        if (empty($this->errors)) return null;
        $first = reset($this->errors);
        return is_array($first) ? $first[0] : $first;
    }
    
    /**
     * Get errors for specific field
     */
    public function getError(string $field): array {
        return $this->errors[$field] ?? [];
    }
    
    /**
     * Get validated data
     */
    public function validated(): array {
        $validated = [];
        foreach ($this->rules as $field => $rules) {
            $validated[$field] = $this->data[$field] ?? null;
        }
        return $validated;
    }
}
