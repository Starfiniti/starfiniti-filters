(() => {
  'use strict';
  const { registerBlockType } = window.wp.blocks;
  const { createElement } = window.wp.element;
  const { __ } = window.wp.i18n;
  registerBlockType('starfiniti/discovery', {
    edit: () => createElement('div', { className: 'components-placeholder' }, createElement('strong', {}, __('Starfiniti Product Discovery', 'starfiniti-search')), createElement('p', {}, __('Faceted product results are rendered on the storefront.', 'starfiniti-search'))),
    save: () => null,
  });
})();
