# Timing API Project Importer

## Introduction

This PHP script allows you to bulk import and update projects in [Timing](https://timingapp.com/) using the [Timing Web API](https://web.timingapp.com/docs). It's designed to help users easily create and manage projects based on a CSV file, making it ideal for e.g. importing a list of projects from another system or setting up new projects with auto-categorization rules.

## Features

- Bulk import projects from a CSV file
- Create new projects with auto-categorization rules
- Update existing projects with new auto-categorization rules
- You can add projects as sub-proejcts to an existing project, and/or add them to a specific team
- Flexible configuration options via command-line arguments or JSON config file
- Dry-run mode for testing without making actual changes
- Verbose output for detailed logging

## Requirements

- PHP 8.3 or higher, with the cURL extension enabled
- A Timing account with API access (requires the [Connect plan](https://timingapp.com/pricing))
- Timing API key (generate one at [https://web.timingapp.com/integrations/tokens](https://web.timingapp.com/integrations/tokens))

## Installation

1. Clone this repository or download the script files:
   - `importProjects.php`
   - `exampleConfig.json`
   - `exampleProjects.csv`

2. Ensure you have PHP installed on your system. You can check by running:
   ```
   php --version
   ```

3. Make sure the cURL extension is enabled in your PHP installation. This should be the case for most PHP installations.

## Configuration

### exampleConfig.json

This file contains the configuration options for the script. Here's an example:

```json
{
    "team": "The ID of the team you want to add projects to; if you don't know it, just specify an invalid ID and the script will print out the valid IDs",
    "csv-delimiter": ";",
    "term-delimiter": ",",
    "parent": "The name of the project that all of these projects will get added to",
    "update-existing": true,
    "verbose": true,
    "file": "exampleProjects.csv",
    "TIMING_API_KEY": "An API key generated at https://web.timingapp.com/integrations/tokens"
}
```

Customize this file with your specific settings:

- `team`: Your Timing team ID (the script will help you find this if you're unsure)
- `csv-delimiter`: The delimiter used in your CSV file (default is `;`)
- `term-delimiter`: The delimiter used for multiple terms within a single CSV cell (default is `,`)
- `parent`: The name of a parent project to add all imported projects under (optional)
- `update-existing`: Set to `true` to update existing projects, `false` to skip them
- `verbose`: Set to `true` for detailed output during execution
- `dry-run`: Set to `true` to have the script preview changes without actually making them
- `file`: The name of your CSV file containing project data
- `TIMING_API_KEY`: Your Timing API key

You can also specify these options directly on the command line when running the script, but we recommend using a configuration file for convenience and documentation.

### exampleProjects.csv

This CSV file contains the projects you want to import. Here's an example:

```csv
Name;Rules
Name of the project to create;any app activity having this text in its title or path will match,same for this text,#keyword
```

- The first column is the project name
- The second column contains the auto-categorization rules, separated by the `term-delimiter` (usually `,`)
- Use `#` before a term to specify a "Keywords contain" rule rather than a "Title or path contains" rule

## Usage

### Basic Usage

After setting up your configuration file and CSV file, you can run the script like this:

```
php importProjects.php --env=exampleConfig.json --dry-run
```

This will run the script in dry-run mode, showing you what changes it would make without actually making them. Once you're satisfied with the results, remove the `--dry-run` option to apply the changes:

```
php importProjects.php --env=exampleConfig.json
```

### Examples

1. Using a config file:
   ```
   php importProjects.php --env=myConfig.json
   ```

2. Specifying options on the command line:
   ```
   php importProjects.php --team=/teams/123 --csv-delimiter=";" --term-delimiter="," --parent="My Parent Project" --update-existing --verbose myProjects.csv
   ```

3. Dry run with verbose output:
   ```
   php importProjects.php --env=myConfig.json --dry-run --verbose
   ```

## How It Works

1. The script reads the configuration from the JSON file and/or command-line arguments.
2. It connects to the Timing API and retrieves the list of teams and existing projects.
3. The CSV file is parsed, extracting project names and auto-categorization rules.
4. For each project in the CSV:
   - If the project doesn't exist, it's created with the specified rules.
   - If the project exists and `update-existing` is true, it's updated with new rules.
5. The script provides feedback on the actions taken, especially in verbose mode.

## Troubleshooting

- Double-check your CSV file format if you see parsing errors.
- Start by using the `--dry-run` option to see what changes the script would make.
- Use the `--verbose` option to get more detailed information about what the script is doing.
- If you encounter permission errors, ensure your API key is valid.
- If you're unsure about your team ID, run the script with an invalid ID, and it will list the available teams.

## Limitations and Considerations

- The script currently doesn't support deleting projects or removing existing rules.
- Large CSV files may take some time to process due to API rate limits.
- Ensure your project names are unique to avoid conflicts.

## Disclaimer

This script is provided as-is, without any warranty or support. While we've made efforts to ensure it works correctly, use it at your own risk. We do not provide official support for this script.