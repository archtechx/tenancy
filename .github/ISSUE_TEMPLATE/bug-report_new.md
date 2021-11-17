name: Bug
description: File a bug report
body:
- type: markdown
  attributes:
  value: |
  Before opening a bug report, please search for the behaviour in the existing issues.

      ---
      
      Thank you for taking the time to file a bug report. To address this bug as fast as possible, we need some information.
- type: input
  id: os
  attributes:
  label: Operating system
  description: "Which operating system do you use? Please provide the version as well."
  placeholder: "macOS Big Sur 11.5.2"
  validations:
  required: true
- type: input
  id: laravel
  attributes:
  label: Laravel Version
  description: "Please provide the full Laravel version of your project."
  placeholder: "v8.6.1"
  validations:
  required: true
- type: input
  id: php
  attributes:
  label: PHP Version
  description: "Please provide the full PHP version that is powering Tinkerwell."
  placeholder: "PHP 8.0.7, built: Jun  4 2021 01:50:04"
  validations:
  required: true
- type: dropdown
  id: location
  attributes:
  label: Project Location
  description: Where is the project located?
  options:
  - Local
  - Remote
  - Somewhere else (please specify in the description!)
  validations:
  required: true
- type: textarea
  id: bug-description
  attributes:
  label: Bug description
  description: What happened?
  validations:
  required: true
- type: textarea
  id: steps
  attributes:
  label: Steps to reproduce
  description: Which steps do we need to take to reproduce this error?
- type: textarea
  id: logs
  attributes:
  label: Relevant log output
  description: If applicable, provide relevant log output. No need for backticks here.
  render: shell
