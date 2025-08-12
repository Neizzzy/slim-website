<?php

namespace App\Validator;

class CarValidator
{
    public function validate(array $car): array
    {
        $errors = [];
        if (empty($car['make'])) {
            $errors['make'] = "Field 'Make' can't be blank!";
        }
        if (empty($car['model'])) {
            $errors['model'] = "Field 'Model' can't be blank!";
        }

        return $errors;
    }
}