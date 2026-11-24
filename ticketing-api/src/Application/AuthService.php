<?php
declare(strict_types=1);

namespace Ticketing\Application;

use stdClass;

final class AuthService
{
    public function __construct(private readonly AuthRepository $repository)
    {
    }

    public function register(stdClass $body): array
    {
        $name = is_string($body->name ?? null) ? trim($body->name) : '';
        $email = is_string($body->email ?? null) ? strtolower(trim($body->email)) : '';
        $password = $body->password ?? null;

        if ($name === '' || strlen($name) > 120 ||
            strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($password) || strlen($password) < 12 ||
            strlen($password) > 72 || str_contains($password, "\0")) {
            throw new ApplicationError(ErrorKind::Validation, 'Ingresa un nombre y un correo válidos y una contraseña de 12 a 72 bytes.');
        }

        $user = $this->repository->create($name, $email, password_hash($password, PASSWORD_BCRYPT));
        if ($user === false) {
            throw new ApplicationError(ErrorKind::Conflict, 'El correo electrónico ya está registrado.');
        }

        return $user;
    }

    public function login(stdClass $body): array
    {
        $email = is_string($body->email ?? null) ? strtolower(trim($body->email)) : '';
        $password = $body->password ?? null;

        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($password) || strlen($password) < 12 ||
            strlen($password) > 72 || str_contains($password, "\0")) {
            throw new ApplicationError(ErrorKind::Unauthenticated, 'Correo electrónico o contraseña incorrectos.');
        }

        $user = $this->repository->findByEmail($email);

        // Use a dummy hash to perform the password check even for an unknown email.
        $hash = $user['password_hash'] ??
            '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || $user === false) {
            throw new ApplicationError(ErrorKind::Unauthenticated, 'Correo electrónico o contraseña incorrectos.');
        }

        $token = bin2hex(random_bytes(32));
        $this->repository->storeSession((int) $user['id'], hash('sha256', $token));

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 86400];
    }

    public function authenticate(string $authorization): array
    {
        if (preg_match('/^Bearer ([a-f0-9]{64})$/iD', $authorization, $matches)) {
            $user = $this->repository->findActiveUser(hash('sha256', $matches[1]));
            if ($user !== false) {
                return $user;
            }
        }

        throw new ApplicationError(ErrorKind::Unauthenticated, 'Se requiere autenticación.');
    }

    public function requireAdmin(string $authorization): void
    {
        $user = $this->authenticate($authorization);
        if ($user['role'] !== 'admin') {
            throw new ApplicationError(ErrorKind::Forbidden, 'Se requieren permisos de administrador.');
        }
    }
}
