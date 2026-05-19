<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuctionRuleRequest;
use App\Http\Resources\AuctionRuleResource;
use App\Models\AuctionRule;
use App\Traits\ApiResponseTrait;

class AuctionRuleController extends Controller
{
    use ApiResponseTrait;

    public function index()
    {
        $rules = AuctionRule::ordered()->get();
        return AuctionRuleResource::collection($rules);
    }

    public function store(AuctionRuleRequest $request)
    {
        $rule = AuctionRule::create($request->validated());
        return new AuctionRuleResource($rule);
    }

    public function show($id)
    {
        $rule = AuctionRule::find($id);
        if (!$rule) {
            return $this->sendError('القاعدة غير موجودة.', 404);
        }
        return new AuctionRuleResource($rule);
    }

    public function update(AuctionRuleRequest $request, $id)
    {
        $rule = AuctionRule::find($id);
        if (!$rule) {
            return $this->sendError('القاعدة غير موجودة.', 404);
        }
        $rule->update($request->validated());
        return new AuctionRuleResource($rule->fresh());
    }

    public function destroy($id)
    {
        $rule = AuctionRule::find($id);
        if (!$rule) {
            return $this->sendError('القاعدة غير موجودة.', 404);
        }
        $rule->delete();
        return $this->sendResponse([], 'تم حذف القاعدة بنجاح.');
    }
}