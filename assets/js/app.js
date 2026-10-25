/* assets/js/app.js - Dynamic Interface & Helper JS */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Sidebar Toggle & Mobile Overlay
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

  function toggleSidebar() {
    if (!sidebar) return;
    sidebar.classList.toggle('collapsed');
    const isMobile = window.innerWidth <= 768;

    if (isMobile && sidebarOverlay) {
      if (!sidebar.classList.contains('collapsed')) {
        sidebarOverlay.classList.add('active');
      } else {
        sidebarOverlay.classList.remove('active');
      }
    } else {
      localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
    }
  }

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', toggleSidebar);

    if (sidebarOverlay) {
      sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.add('collapsed');
        sidebarOverlay.classList.remove('active');
      });
    }

    // Default mobile behavior: start collapsed
    if (window.innerWidth <= 768) {
      sidebar.classList.add('collapsed');
    } else if (localStorage.getItem('sidebar_collapsed') === 'true') {
      sidebar.classList.add('collapsed');
    }
  }

  // Handle window resize
  window.addEventListener('resize', () => {
    if (window.innerWidth > 768 && sidebarOverlay) {
      sidebarOverlay.classList.remove('active');
    }
  });

  // 2. Entity Currency Lock in Transaction Forms
  const entitySelect = document.getElementById('entity_id');
  const currencyDisplay = document.getElementById('currency_display');
  const currencyInput = document.getElementById('currency_input');
  const paymentAccountSelect = document.getElementById('payment_account_id');
  const categoryAccountSelect = document.getElementById('category_account_id');

  if (entitySelect) {
    entitySelect.addEventListener('change', () => {
      const selectedOption = entitySelect.options[entitySelect.selectedIndex];
      const currency = selectedOption ? selectedOption.getAttribute('data-currency') : null;

      if (currency) {
        if (currencyDisplay) currencyDisplay.textContent = currency;
        if (currencyInput) currencyInput.value = currency;

        // Apply theme visual highlights on card or form
        const formCard = document.getElementById('tx_form_card');
        if (formCard) {
          formCard.classList.remove('inr-theme', 'usdt-theme');
          if (currency === 'INR') {
            formCard.classList.add('inr-theme');
          } else if (currency === 'USDT') {
            formCard.classList.add('usdt-theme');
          }
        }

        // Filter account options matching currency
        filterAccountOptions(paymentAccountSelect, currency);
        filterAccountOptions(categoryAccountSelect, currency);
      }
    });

    // Initial trigger
    if (entitySelect.value) {
      entitySelect.dispatchEvent(new Event('change'));
    }
  }

  function filterAccountOptions(selectElement, currency) {
    if (!selectElement) return;
    const options = selectElement.querySelectorAll('option');
    options.forEach(opt => {
      const optCurrency = opt.getAttribute('data-currency');
      if (!optCurrency || optCurrency === 'BOTH' || optCurrency === currency || opt.value === '') {
        opt.style.display = '';
        opt.disabled = false;
      } else {
        opt.style.display = 'none';
        opt.disabled = true;
      }
    });
    if (selectElement.selectedOptions[0] && selectElement.selectedOptions[0].disabled) {
      selectElement.value = '';
    }
  }

  // 3. Tab Switching for Dashboard INR / USDT
  const dashboardTabs = document.querySelectorAll('.dash-tab-btn');
  dashboardTabs.forEach(tab => {
    tab.addEventListener('click', (e) => {
      e.preventDefault();
      const targetCurrency = tab.getAttribute('data-currency');

      dashboardTabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      document.querySelectorAll('.currency-section').forEach(sec => {
        if (sec.id === `section_${targetCurrency}`) {
          sec.style.display = 'block';
        } else {
          sec.style.display = 'none';
        }
      });
    });
  });
});

/**
 * Global Helper to export table content to CSV
 */
function exportTableToCSV(tableId, filename) {
  const table = document.getElementById(tableId);
  if (!table) return;

  const rows = table.querySelectorAll('tr');
  const csv = [];

  rows.forEach(row => {
    const cols = row.querySelectorAll('th, td');
    const rowData = [];
    cols.forEach(col => {
      let text = col.innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""');
      rowData.push(`"${text}"`);
    });
    csv.push(rowData.join(','));
  });

  const csvFile = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const downloadLink = document.createElement('a');
  downloadLink.download = filename || 'export.csv';
  downloadLink.href = window.URL.createObjectURL(csvFile);
  downloadLink.style.display = 'none';
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}
