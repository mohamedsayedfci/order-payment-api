<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\SaveOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $orders = $this->orderService->paginate($request->user(), $request->validated());

        return OrderResource::collection($orders);
    }

    public function store(SaveOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create($request->user(), $request->validated());

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order): OrderResource
    {
        $ownedOrder = $this->orderService->find($request->user(), $order);

        return new OrderResource($ownedOrder);
    }

    public function update(SaveOrderRequest $request, int $order): OrderResource
    {
        $updatedOrder = $this->orderService->update($request->user(), $order, $request->validated());

        return new OrderResource($updatedOrder);
    }

    public function destroy(Request $request, int $order): Response
    {
        $this->orderService->delete($request->user(), $order);

        return response()->noContent();
    }
}
