<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectAttachment>
 */
class ProjectAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'uploaded_by' => User::factory(),
            'kind' => 'documents',
            'original_name' => 'project-document.pdf',
            'disk' => 'local',
            'path' => 'projects/documents/example.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ];
    }
}
