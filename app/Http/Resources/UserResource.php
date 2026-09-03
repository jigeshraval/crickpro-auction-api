<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->maskMobile($this->mobile),
            'emailVerifiedAt' => $this->email_verified_at,
            'verified' => (bool) $this->verified,
            'profileImageUrl' => $this->profile_image ? asset('storage/'.$this->profile_image) : null,
            'createdAt' => $this->created_at,
        ];
    }

    private function maskMobile(?string $mobile): ?string
    {
        if (! $mobile || strlen($mobile) < 6) {
            return $mobile;
        }

        return str_repeat('*', strlen($mobile) - 4).substr($mobile, -4);
    }
}
