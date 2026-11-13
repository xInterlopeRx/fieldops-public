<?php

namespace App\Services;

use App\Models\Project;

class ProjectNumberGenerator
{
    public function assign(Project $project): Project
    {
        if (is_string($project->project_number) && trim($project->project_number) !== '') {
            return $project;
        }

        $base = 'P'.str_pad((string) $project->getKey(), 5, '0', STR_PAD_LEFT);
        $number = $base;
        $suffix = 1;

        while (Project::query()
            ->where('project_number', $number)
            ->whereKeyNot($project->getKey())
            ->exists()) {
            $number = $base.'-'.$suffix;
            $suffix++;
        }

        $project->forceFill(['project_number' => $number])->save();

        return $project;
    }
}
