<?php

namespace App\Http\Controllers\Api;

use App\Support\ApiContract;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1
 *
 * The discovery document. Tells a client what this deployment actually offers
 * and which version it should expect, so a build written against a surface
 * that has since been removed can fail loudly on its first call instead of on
 * whichever screen happens to touch the missing field.
 *
 * The body comes from ApiContract, the same place the X-Api-Version header is
 * read from, so the two cannot disagree.
 */
class DiscoveryController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->json(ApiContract::discovery());
    }
}