import { t, tn } from '../../core/api';

/**
 * What the rail says when it holds no folders.
 *
 * There are two empty libraries and only one of them used to have a sentence.
 * *"No folders yet"* is true of a site nobody has filed. It is **false** on a
 * site where FileBird holds twenty folders and eighty-nine file assignments,
 * which `Modules\Import\Catalog` could already see and nothing ever said. This
 * is not a notice added to a screen; it is a wrong sentence replaced with a
 * right one, and it removes itself the moment a folder exists here.
 *
 * The vendor is named on purpose — Nick's decision. Real Media Library says
 * *"another plugin for folders"*, which tells a user there is a door without
 * telling them which one. Naming is permitted in admin UI; the wp.org ban
 * covers readme tags and slugs.
 *
 * `elsewhere` arrives in `window.folderFolio`, server-rendered, so the correct
 * sentence is in the markup on first paint rather than replacing a wrong one a
 * moment later. `Modules\Import\Elsewhere` has the cost of computing it and
 * why it is almost never computed.
 */
export function EmptyTree() {
    const elsewhere = window.folderFolio?.elsewhere;

    if (!elsewhere) {
        return <p className="folderfolio-rail__empty">{t('emptyTree', 'No folders yet')}</p>;
    }

    /*
     * The counts are phrased before they are placed, the same way the import
     * report assembles its finished sentence: a template with `%s folders` in
     * it cannot be made singular by any translator.
     */
    const folders = tn(
        'emptyElsewhereFolderOne',
        'emptyElsewhereFolderMany',
        elsewhere.folders,
        '%s folder',
        '%s folders',
        elsewhere.folders
    );

    const files = tn(
        'emptyElsewhereFileOne',
        'emptyElsewhereFileMany',
        elsewhere.files,
        '%s file',
        '%s files',
        elsewhere.files
    );

    return (
        <div className="folderfolio-rail__empty folderfolio-elsewhere">
            <p className="folderfolio-elsewhere__title">
                {t('emptyElsewhereTitle', 'Your media is already filed — just not here.')}
            </p>

            <p className="folderfolio-elsewhere__body">
                {t(
                    'emptyElsewhereBody',
                    '%1$s has %2$s holding %3$s. Bringing them over adds them to FolderFolio — nothing is moved, and nothing is removed from where it is now.',
                    elsewhere.label,
                    folders,
                    files
                )}
            </p>

            {elsewhere.others > 0 && (
                <p className="folderfolio-elsewhere__more">
                    {tn(
                        'emptyElsewhereOtherOne',
                        'emptyElsewhereOtherMany',
                        elsewhere.others,
                        '%s other plugin has folders here too.',
                        '%s other plugins have folders here too.',
                        elsewhere.others
                    )}
                </p>
            )}

            {elsewhere.importUrl !== '' && (
                <a
                    className="button button-primary folderfolio-elsewhere__action"
                    href={elsewhere.importUrl}
                >
                    {t('emptyElsewhereAction', 'Review the import')}
                </a>
            )}
        </div>
    );
}
