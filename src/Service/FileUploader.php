<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FileUploader
{
    private $targetDirectory;
    private $publicDirectory;
    private $validator;

    public function __construct(string $targetDirectory, string $publicDirectory, ValidatorInterface $validator)
    {
        $this->targetDirectory = $targetDirectory;
        $this->publicDirectory = $publicDirectory;
        $this->validator = $validator;
    }

    public function upload(UploadedFile $file)
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
    
    public function isValidImage(UploadedFile $file) {
        if(!$file) {
            return false;
        }
        
        $imageConstraint = new Assert\Image([
                'maxSize' => '5m'
            ]);
            
        $errors = $this->validator->validate($file, $imageConstraint);
        return ($errors->count() === 0);
    }

    public function getTargetDirectory()
    {
        return $this->targetDirectory;
    }
    
    public function getPublicDirecotry() {
        return $this->publicDirectory;        
    }
}