<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\ImageUrlGenerator;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FileUploader
{
    public function __construct(
        private readonly FilesystemOperator $storage,
        private readonly ImageUrlGenerator $urlGenerator,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function upload(UploadedFile $file): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        $fileName = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        $stream = fopen($file->getPathname(), 'rb');
        if (false === $stream) {
            throw new FileException('Cannot read uploaded file: '.$file->getPathname());
        }

        try {
            $this->storage->writeStream($fileName, $stream);
        } catch (FilesystemException $e) {
            throw new FileException('Failed to store uploaded file: '.$e->getMessage(), previous: $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $fileName;
    }

    public function isValidImage(UploadedFile $file): bool
    {
        $imageConstraint = new Assert\Image(
            maxSize: '5m',
        );

        $errors = $this->validator->validate($file, $imageConstraint);

        return 0 === $errors->count();
    }

    public function getPublicUrl(string $filename): string
    {
        return $this->urlGenerator->exportUrl($filename);
    }
}
