<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderAttachment>
 */
class WorkOrderAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'uploaded_by' => User::factory(),
            'kind' => 'documents',
            'original_name' => 'document.pdf',
            'disk' => 'local',
            'path' => 'work-orders/documents/example.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ];
    }
}
