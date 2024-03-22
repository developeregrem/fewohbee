<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FileUploader
{
    public function __construct(
        private readonly string $targetDirectory,
        private readonly string $publicDirectory,
        private readonly ValidatorInterface $validator
    ) {
    }

    public function upload(UploadedFile $file): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        $fileName = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        try {
            $file->move($this->getTargetDirectory(), $fileName);
        } catch (FileException $e) {
            throw $e;
        }

        return $fileName;
    }

    public function isValidImage(UploadedFile $file): bool
    {
        $imageConstraint = new Assert\Image([
                'maxSize' => '5m',
            ]);

        $errors = $this->validator->validate($file, $imageConstraint);

        return 0 === $errors->count();
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    public function getPublicDirectory(): string
    {
        return $this->publicDirectory;
    }
}
