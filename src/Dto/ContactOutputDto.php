<?php
namespace App\Dto;

use App\Entity\Contact;

class ContactOutputDto
{
    public int $id;
    public string $name;
    public string $email;
    public string $phone;
    public string $subject;
    public string $Content;
    public string $createdAt;
    public ?bool $isRead;

    public function __construct(Contact $contact)
    {
        $this->id = $contact->getId();
        $this->name = $contact->getName();
        $this->email = $contact->getEmail();
        $this->phone = $contact->getPhone();
        $this->subject = $contact->getSubject();
        $this->Content = $contact->getContent();
        $this->createdAt = $contact->getCreatedAt()->format('Y-m-d H:i:s');
        $this->isRead = $contact->isIsRead();
    }
}
