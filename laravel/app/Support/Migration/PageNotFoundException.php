<?php
namespace App\Support\Migration;
class PageNotFoundException extends \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
{
    public static function forPageNotFound(string $message = 'Record not found.'): static { return new static($message); }
}
