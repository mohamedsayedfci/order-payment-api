<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListPaymentsRequest;
use App\Http\Requests\ProcessPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService)
    {
    }

    public function index(ListPaymentsRequest $request, ?int $order = null): AnonymousResourceCollection
    {
        $payments = $this->paymentService->paginate($request->user(), $request->validated(), $order);

        return PaymentResource::collection($payments);
    }

    public function store(ProcessPaymentRequest $request, int $order): JsonResponse
    {
        $result = $this->paymentService->process($request->user(), $order, $request->validated());
        $statusCode = $result->replayed ? 200 : 201;
        $replayedHeader = $result->replayed ? 'true' : 'false';

        return (new PaymentResource($result->payment))->response()
            ->setStatusCode($statusCode)
            ->header('Idempotent-Replayed', $replayedHeader);
    }

    public function show(Request $request, string $payment): PaymentResource
    {
        $ownedPayment = $this->paymentService->find($request->user(), $payment);

        return new PaymentResource($ownedPayment);
    }
}
