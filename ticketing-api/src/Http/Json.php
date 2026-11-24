<?php
declare(strict_types=1);

namespace Ticketing\Http;

use JsonException;
use stdClass;

final class Json
{
    public static function body(): stdClass
    {
        try {
            $body = json_decode(file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::respond(400, ['error' => 'JSON no válido.']);
        }

        if (!$body instanceof stdClass) {
            self::respond(400, ['error' => 'El cuerpo debe ser un objeto JSON.']);
        }

        return $body;
    }

    public static function respond(int $status, array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
