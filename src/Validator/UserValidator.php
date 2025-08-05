<?php

namespace App\Validator;

class UserValidator
{
    public function validate(array $user): array
    {
        $errors = [];
        if (empty($user['nickname'])) {
            $errors['nickname'] = "Field 'Nickname' can't be blank!";
        }
        if (empty($user['email'])) {
            $errors['email'] = "Field 'Email' can't be blank!";
        }

        return $errors;
    }
}