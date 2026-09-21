<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * Built-in templates (F1: at minimum Blank SOP, Onboarding, Client Delivery
 * Playbook, Policy). Workspace-saved templates arrive later; these are static.
 * Templates are offered second — the first-run screen pushes to recording (S4).
 */
final class Templates
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'id' => 'blank-sop', 'name' => 'Blank SOP', 'doc_type' => 'sop',
                'description' => 'Purpose, scope, prerequisites, steps and outcome — empty.',
                'content' => Content::empty(), 'steps' => [],
            ],
            [
                'id' => 'onboarding', 'name' => 'Onboarding', 'doc_type' => 'sop',
                'description' => 'Bring a new starter from offer to first week.',
                'content' => Content::merge(Content::empty(), [
                    'purpose' => 'Get a new team member set up and productive in their first week.',
                    'scope' => 'Applies to every new hire, contractor or intern.',
                    'prerequisites' => ['Signed contract', 'Manager assigned', 'Laptop ordered'],
                    'outcome' => 'The new starter has access to every tool they need and has completed the first-week checklist.',
                ]),
                'steps' => [
                    ['instruction' => 'Create accounts: email, chat, and the tools for their role.', 'is_checkpoint' => true],
                    ['instruction' => 'Send the welcome email with start time, location and what to bring.'],
                    ['instruction' => 'Assign a buddy for the first two weeks.'],
                    ['instruction' => 'Walk through the handbook and collect policy acknowledgements.', 'is_critical' => true],
                    ['instruction' => 'Book the end-of-week check-in with their manager.', 'is_checkpoint' => true],
                ],
            ],
            [
                'id' => 'client-delivery', 'name' => 'Client Delivery Playbook', 'doc_type' => 'sop',
                'description' => 'From signed proposal to handover, the way this team delivers.',
                'content' => Content::merge(Content::empty(), [
                    'purpose' => 'Deliver client work consistently regardless of who is on the account.',
                    'scope' => 'Every client engagement from kickoff to handover.',
                    'prerequisites' => ['Signed proposal', 'Named account lead'],
                    'outcome' => 'Client has received the deliverables, signed off, and the account is in maintenance.',
                ]),
                'steps' => [
                    ['instruction' => 'Create the client folder from the template and rename it with the client name.'],
                    ['instruction' => 'Hold the kickoff call and record decisions in the intake document.', 'is_critical' => true],
                    ['instruction' => 'Set milestones and delivery dates in the tracker.'],
                    ['instruction' => 'Send the weekly status update every Friday.', 'is_checkpoint' => true],
                    ['instruction' => 'Run the handover session and obtain written sign-off.', 'is_critical' => true],
                ],
            ],
            [
                'id' => 'policy', 'name' => 'Policy', 'doc_type' => 'policy',
                'description' => 'A policy page for the handbook, with acknowledgement.',
                'content' => Content::merge(Content::empty(), [
                    'purpose' => 'State the rule, who it applies to, and what happens when it is not followed.',
                    'scope' => 'All employees.',
                    'blocks' => [
                        ['id' => 'p1', 'type' => 'heading', 'level' => 2, 'text' => 'Policy statement'],
                        ['id' => 'p2', 'type' => 'paragraph', 'text' => ''],
                        ['id' => 'p3', 'type' => 'heading', 'level' => 2, 'text' => 'Responsibilities'],
                        ['id' => 'p4', 'type' => 'bullet_list', 'items' => []],
                        ['id' => 'p5', 'type' => 'heading', 'level' => 2, 'text' => 'Exceptions'],
                        ['id' => 'p6', 'type' => 'paragraph', 'text' => ''],
                    ],
                ]),
                'steps' => [],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }

        return null;
    }
}
