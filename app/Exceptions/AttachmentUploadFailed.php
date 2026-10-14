<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AttachmentUploadFailed extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The attachment could not be uploaded. Try again or contact an administrator.', 0, $previous);
    }

    public function render(Request $request): Response
    {
        $status = min(max($this->httpStatus, 400), 599);

        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], $status);
        }

        return back()->withErrors(['files' => $this->getMessage()]);
    }
}
