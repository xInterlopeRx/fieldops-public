<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactMethod;
use Illuminate\Validation\ValidationException;

class ContactMethodService
{
    public function __construct(private readonly OutlookContactSyncCoordinator $outlookSync) {}

    /** @param array<int, array{id?: int|null, method_type: string, label: string, value: string, is_primary: bool}> $methods */
    public function sync(Contact $contact, array $methods): void
    {
        $lockedContact = Contact::query()
            ->whereKey($contact->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $existingMethods = $lockedContact->methods()
            ->orderBy('id')
            ->get()
            ->keyBy(fn (ContactMethod $method): int => (int) $method->getKey());

        $submittedIds = collect($methods)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $existingIds = $existingMethods->keys()->map(fn (int|string $id): int => (int) $id);
        $submittedMethodsById = collect($methods)
            ->filter(fn (array $method): bool => ($method['id'] ?? null) !== null)
            ->keyBy(fn (array $method): int => (int) $method['id']);

        if (
            $submittedIds->duplicates()->isNotEmpty() ||
            $submittedIds->diff($existingIds)->isNotEmpty()
        ) {
            throw ValidationException::withMessages([
                'contacts' => 'Communication methods must belong to their Contact and may only appear once.',
            ]);
        }

        $removedIds = $existingIds->diff($submittedIds)->all();
        $hasChanges = $removedIds !== [] || count($methods) !== $existingMethods->count();

        foreach ($methods as $methodData) {
            $methodId = $methodData['id'] ?? null;

            if ($methodId === null) {
                $hasChanges = true;

                continue;
            }

            $existingMethod = $existingMethods->get((int) $methodId);

            if (
                $existingMethod !== null && (
                    $existingMethod->method_type->value !== $methodData['method_type'] ||
                    $existingMethod->label->value !== $methodData['label'] ||
                    $existingMethod->value !== $methodData['value'] ||
                    $existingMethod->is_primary !== (bool) $methodData['is_primary']
                )
            ) {
                $hasChanges = true;
            }
        }

        if ($removedIds !== []) {
            $lockedContact->methods()->whereKey($removedIds)->delete();
        }

        foreach ($existingMethods as $id => $existingMethod) {
            $submittedMethod = $submittedMethodsById->get((int) $id);

            if (
                $existingMethod->is_primary &&
                is_array($submittedMethod) &&
                (
                    ! $submittedMethod['is_primary'] ||
                    $submittedMethod['method_type'] !== $existingMethod->method_type->value
                )
            ) {
                $existingMethod->forceFill(['is_primary' => false])->save();
            }
        }

        foreach ($methods as $methodData) {
            $methodId = $methodData['id'] ?? null;
            $method = $methodId === null
                ? new ContactMethod
                : $existingMethods->get((int) $methodId);

            $method->fill([
                'method_type' => $methodData['method_type'],
                'label' => $methodData['label'],
                'value' => $methodData['value'],
                'is_primary' => $methodData['is_primary'],
            ]);

            $lockedContact->methods()->save($method);
        }

        if ($hasChanges) {
            $this->outlookSync->queue((int) $lockedContact->getKey());
        }
    }
}
