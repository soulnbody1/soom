<?php

namespace App\Traits;

use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;


trait ApiResponseTrait
{
    public function sendResponse($data = [], string $message = '', int $status = 200, array $extra = []): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data instanceof AnonymousResourceCollection
            && ! ($data->resource instanceof AbstractPaginator)) {
            $response['data'] = $data;
        } elseif ($data instanceof AbstractPaginator || $data instanceof AnonymousResourceCollection) {
            $response['data'] = $data->items();
            $response['current_page'] = $data->currentPage();
            $response['last_page'] = $data->lastPage();
            $response['per_page'] = $data->perPage();
            $response['total'] = $data->total();
            $response['next_page_url'] = $data->nextPageUrl();
            $response['prev_page_url'] = $data->previousPageUrl();
        } else {
            $response['data'] = $data;
        }

        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        return response()->json($response, $status);
    }


    public function sendEmptyResponse(string $message = '', int $status = 200): JsonResponse
    {
        return $this->sendResponse([], $message, $status);
    }

    public function sendError(string $message = '', int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
