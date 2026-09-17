<?php

namespace Database\Seeders;

use App\Models\ShareholderCategory;
use Illuminate\Database\Seeder;

class ShareholderCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'A', 'name' => 'INDIVIDUAL', 'default_holder_type' => 'individual'],
            ['code' => 'I', 'name' => 'INDIVIDUAL', 'default_holder_type' => 'individual'],
            ['code' => 'C', 'name' => 'CORPORATE BODY', 'default_holder_type' => 'corporate'],
            ['code' => 'D', 'name' => 'DIRECTOR', 'default_holder_type' => 'individual'],
            ['code' => 'J', 'name' => 'INDIVIDUAL-JOINT', 'default_holder_type' => 'individual', 'requires_joint_holders' => true],
            ['code' => 'M', 'name' => 'STAFF PENS. & PROV. FUNDS', 'default_holder_type' => 'corporate'],
            ['code' => 'N', 'name' => 'UNIVERSITY & COLLEGES', 'default_holder_type' => 'corporate'],
            ['code' => 'O', 'name' => 'NON-POLITICAL ORGANISATION', 'default_holder_type' => 'corporate'],
            ['code' => 'P', 'name' => 'STATE INSTITUTIONS', 'default_holder_type' => 'corporate'],
            ['code' => 'Q', 'name' => 'STATE INVESTMENT COMPANIES', 'default_holder_type' => 'corporate'],
            ['code' => 'R', 'name' => 'OTHER SPECIAL INT. GROUP', 'default_holder_type' => null, 'requires_review' => true],
            ['code' => 'S', 'name' => 'STAFF', 'default_holder_type' => 'individual'],
            ['code' => 'T', 'name' => 'INSURANCE', 'default_holder_type' => 'corporate'],
            ['code' => 'U', 'name' => 'COOPERATIVE SOCIETIES', 'default_holder_type' => 'corporate'],
            ['code' => 'V', 'name' => 'FOREIGN SHAREHOLDERS', 'default_holder_type' => null, 'requires_review' => true],
            ['code' => 'X', 'name' => 'JOINT', 'default_holder_type' => 'individual', 'requires_joint_holders' => true],
            ['code' => 'Y', 'name' => 'CSCS', 'default_holder_type' => 'corporate'],
            ['code' => 'Z', 'name' => 'AMCON', 'default_holder_type' => 'corporate'],
        ];

        foreach ($categories as $category) {
            $model = ShareholderCategory::withTrashed()->updateOrCreate(
                ['code' => $category['code']],
                array_merge([
                    'requires_joint_holders' => false,
                    'requires_review' => false,
                    'is_active' => true,
                    'source_system' => 'ESTOCK',
                ], $category)
            );
            if ($model->trashed()) {
                $model->restore();
            }
        }
    }
}
