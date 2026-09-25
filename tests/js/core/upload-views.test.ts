import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { showUploads, uploadViewCount } from '../../../assets/src/lib/filter';

/** Just enough of a media collection for the set to hold. */
const collection = () => ({ props: { get: () => '' } }) as unknown as Parameters<typeof showUploads>[0];
const element = (connected: boolean) => ({ isConnected: connected }) as unknown as Element;

describe('the grids that show uploads (review L14)', () => {
    it('lets go of a grid whose element has left the page', () => {
        const before = uploadViewCount();
        const gone = element(true);

        showUploads(collection(), element(true));
        showUploads(collection(), gone);
        assert.equal(uploadViewCount(), before + 2);

        // The picker closes and its frame is removed.
        (gone as unknown as { isConnected: boolean }).isConnected = false;
        assert.equal(uploadViewCount(), before + 1);
    });
});
