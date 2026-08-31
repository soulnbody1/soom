<?php

namespace App\Traits;

use App\Http\Middleware\AttachServerTime;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

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

        if (! empty($extra)) {
            $response = array_merge($response, $extra);
        }

        if (request()->attributes->get(AttachServerTime::REQUEST_FLAG) === true) {
            $response = AttachServerTime::stamp($response);
        }

        return response()->json($response, $status);
    }

    public function sendEmptyResponse(string $message = '', int $status = 200): JsonResponse
    {
        return $this->sendResponse([], $message, $status);
    }

    public function sendError(string $message = '', int $status = 400, ?string $code = null): JsonResponse
    {
        return ApiErrorResponse::make($message, $code ?? 'auction_error', $status);
    }
}
