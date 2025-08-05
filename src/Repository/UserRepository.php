<?php

namespace App\Repository;

class UserRepository
{
    private array $users;
    private string $filePath;

    public function __construct(string $path)
    {
        $this->filePath = $path;
        $this->users = $this->loadUsers();
    }

    private function loadUsers(): ?array
    {
        if (!file_exists($this->filePath)) {
            file_put_contents($this->filePath, '[]');
            return [];
        }

        $json = file_get_contents($this->filePath);
        if (!$json) {
            return [];
        }
        return json_decode($json, true, flags: JSON_OBJECT_AS_ARRAY);
    }

    public function all(): array
    {
        return $this->users;
    }

    public function findById(int $id): ?array
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    public function create(array $user): array
    {
        $id = 1;
        if (isset($this->users)) {
            $lastUser = end($this->users);
            $id += $lastUser['id'];
        }
        $user['id'] = $id;

        $this->users[$id] = $user;
        $this->save();

        return $user;
    }

    public function update(int $id, array $data): ?array
    {
        $user = $this->findById($id);
        if (!isset($user)) {
            return null;
        }

        $user['nickname'] = $data['nickname'];
        $user['email'] = $data['email'];
        $this->users[$id] = $user;
        $this->save();

        return $user;
    }

    public function destroy(int $id): void
    {
        unset($this->users[$id]);
        $this->save();
    }

    private function save(): void
    {
        file_put_contents($this->filePath, json_encode($this->users));
    }
}