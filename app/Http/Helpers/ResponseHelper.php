<?php
namespace App\Http\Helpers;

use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ResponseHelper
{
    /**
     * Retour API uniforme avec ou sans pagination
     *
     * @param mixed $data Collection, ResourceCollection ou Paginator
     * @param string|null $message
     * @return array
     */
    public static function success($data, ?string $message = null): array
    {
        // Si c'est une resource, récupérer les données transformées
        if ($data instanceof ResourceCollection) {
            $dataArray = $data->resolve();
        } elseif ($data instanceof Collection || is_array($data)) {
            $dataArray = $data instanceof Collection ? $data->all() : $data;
        } else {
            $dataArray = $data;
        }

        // Si c'est paginé, ajouter meta
        if ($data instanceof AbstractPaginator) {
            return [
                'success' => true,
                'message' => $message,
                'data' => $dataArray,
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ];
        }

        // Cas simple non paginé
        return [
            'success' => true,
            'message' => $message,
            'data' => $dataArray,
        ];
    }

    /**
     * Retour d'erreur uniforme
     *
     * @param string $message
     * @param int $code
     * @param mixed|null $errors
     * @return array
     */
    public static function error(string $message, int $code = 400, $errors = null): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
        ];
    }
}
