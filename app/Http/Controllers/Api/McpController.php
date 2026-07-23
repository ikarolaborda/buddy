<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\RemoteMcpHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('throttle:mcp')]
class McpController extends Controller
{
    public function __construct(
        protected RemoteMcpHandler $handler,
    ) {}

    public function post(Request $request): JsonResponse|Response
    {
        $message = $request->json()->all();

        if (! isset($message['jsonrpc'])) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ], 400);
        }

        $response = $this->handler->handle(
            $message,
            $request->attributes->get('api_client'),
            $request->attributes->get('api_key'),
        );

        if ($response === null) {
            return response()->noContent(202);
        }

        return response()->json($response);
    }

    /*
     * Stateless server: no server-initiated stream is offered, which the
     * Streamable HTTP spec permits via 405 on GET.
     */
    public function get(): Response
    {
        return response()->noContent(405);
    }
}
