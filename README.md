The [mPDF library](https://github.com/mpdf/mpdf) we rely upon for this extension uses Composer and autoloading (as of their version 7 from October 2017):
> Composer is now the only officially supported installation method. There are no pre-packaged library archives.

View mPDF's changelogs at [https://github.com/mpdf/mpdf/releases](https://github.com/mpdf/mpdf/releases)

### Instructions for this extension's build and package process for _a new version of the plugin:_

1. Clone this GitHub repository and create a new branch for your changes.
1. [Install Composer](https://getcomposer.org/download/) into your branch's directory.
    1. Since you may use Composer in other projects, you may want to install Composer at `/Users/YOUR-NAME/composer.phar` and then make a *symlink* pointing to it at **`/Users/YOUR-NAME/git/tribe-ext-pdf-tickets/composer.phar`** so that you can have Composer installed just once on your machine but run it in each project you use Composer for.
    1. Note that the above tip is for Mac (*nix) based and file paths and symlink-type functionality on Windows would have different instructions but likely the same concept.
1. Using Terminal, `cd` to your project directory. Example: `cd /Users/YOUR-NAME/git/tribe-ext-pdf-tickets`
1. **Delete `composer.lock` and the `vendor` subdirectory** (they will get regenerated automatically)
    1. This is not really the normal way to use Composer, but neither is committing the `vendor` subdirectory to your own repository. We need to do it this way to ensure everything gets rebuilt afresh when preparing a plugin version update.
1. Run **`php composer.phar install`**
    1. This will rebuild the `vendor` subdirectory with the latest versions matching the rules in `composer.json`. It will generate the required autoload.php file, the mPDF library, and the libraries required by the autoloader and mPDF. *#Composer just did its magic!*
1. Make your code changes and test them on your localhost. Commit your changes to GitHub (and you would typically add the `Code Review` tag and go through that process).
1. **Once ready to build the finalized .zip to distribute to QA or to customers, run *`php composer.phar archive --format=zip --file tribe-ext-pdf-tickets`***
    1. Because we did not set a `--dir` argument for the `archive` command, Composer will create the .zip right in the project's directory. *#Convenient!*
1. Unzip this newly-created `tribe-ext-pdf-tickets.zip` file to make sure it got built correctly (excluding files like .gitignore, composer.json, etc).
1. Rename this `tribe-ext-pdf-tickets.zip` file to include the version number, e.g. `tribe-ext-pdf-tickets-1-1-0.zip`
    1. We cannot build it with `1-1-0` in the .zip name via Composer because then the extracted folder name will include the version, which is incorrect.
1. Upload this .zip to TheEventsCalendar.com's Media Library (or wherever you want to distribute it, such as uploading to Central for QA to test).
1. Delete this .zip file from your hard drive.

#### Customer Plugin Updates ####

Like some of the other files you see in this GitHub repository, this README.md file does not get included in the final .zip that makes it to the customer. It and other files are excluded via Composer's build process, documented above.

However, if someone updates the plugin via GitHub Updater, they _will_ receive the complete repository, not the Composer-zipped version. This is why the `vendor` subdirectory gets committed to our repository. The instructions above are for publishing the plugin to TheEventsCalendar.com's Extension library.

Therefore, you should delete **all but the DejaVu font set** from `vendor/mpdf/mpdf/ttfonts/` 

### Known Issues ###

mPDF has these known issues, for which you might need to modify the Composer-generated mpdf directory's files **before distributing**:
* [https://github.com/mpdf/mpdf/pull/490/files](https://github.com/mpdf/mpdf/pull/490/files) must be manually performed **after** generating via Composer and **prior to zipping via Composer.** See that pull request's comments for additional context for mPDF v7.0.0. Use this code in place of v7.0.3's Mpdf.php line 20,819:
```
if (!isset($c['miw'])){
	$c['miw'] = $c['maw'];
} else {
	$c['miw'] /= $k;
}
```
* Keep an eye on [https://github.com/mpdf/mpdf/issues/524](https://github.com/mpdf/mpdf/issues/524) so our extension's *get_mpdf()* method can be updated once that's fixed.