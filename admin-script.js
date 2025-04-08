document.addEventListener('DOMContentLoaded', () => {
    // Sidebar navigation
    const navLinks = document.querySelectorAll('.sidebar-nav a');
    const sections = document.querySelectorAll('main section');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = link.getAttribute('href').substring(1);

            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));
            // Add active class to clicked link
            link.classList.add('active');

            // Hide all sections
            sections.forEach(section => section.style.display = 'none');
            // Show target section
            const targetSection = document.getElementById(targetId);
            if (targetSection) {
                targetSection.style.display = 'block';
                window.scrollTo({
                    top: targetSection.offsetTop - 20,
                    behavior: 'smooth'
                });
            }

            // Special case for logout
            if (targetId === 'logout') {
                window.location.href = 'logout';
            }
        });
    });

    // Show the dashboard by default
    document.getElementById('dashboard').style.display = 'block';
});