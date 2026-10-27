(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.workspaceEventsLessonTable = {
    attach(context) {
      once('workspace-events-lesson-table', '.lesson-table__wrapper', context).forEach((container) => {
        const current = container.querySelector('[data-current="true"]');
        if (!current) {
          return;
        }
        const containerRect = container.getBoundingClientRect();
        const currentRect = current.getBoundingClientRect();
        container.scrollTop += currentRect.top - containerRect.top - (container.clientHeight - currentRect.height) / 2;
      });
    },
  };
})(Drupal, once);
