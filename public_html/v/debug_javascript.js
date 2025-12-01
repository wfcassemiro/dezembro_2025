// Script de debug para identificar problemas com a função editLecture
// Cole este código no console do navegador para testar

console.log("🔧 Iniciando debug do JavaScript...");

// 1. Verificar se as funções existem
console.log("📋 Verificando funções:");
console.log("- editLecture existe:", typeof editLecture !== 'undefined');
console.log("- populateLectureForm existe:", typeof populateLectureForm !== 'undefined');
console.log("- getDefaultLectureData existe:", typeof getDefaultLectureData !== 'undefined');

// 2. Verificar se os elementos DOM existem
console.log("🎯 Verificando elementos DOM:");
console.log("- lectureModal:", !!document.getElementById('lectureModal'));
console.log("- modalTitle:", !!document.getElementById('modalTitle'));
console.log("- lectureId:", !!document.getElementById('lectureId'));
console.log("- lectureForm:", !!document.getElementById('lectureForm'));

// 3. Função de teste da editLecture com logs detalhados
function testEditLecture(lectureId) {
    console.log(`🧪 Testando editLecture com ID: ${lectureId}`);
    
    try {
        console.log("📝 Definindo título do modal...");
        document.getElementById('modalTitle').textContent = 'Editar Palestra - DEBUG';
        
        console.log("🆔 Definindo lectureId no form...");
        document.getElementById('lectureId').value = lectureId;
        
        // Verificar se é palestra padrão
        if (lectureId.startsWith('default-')) {
            console.log("⚡ É palestra padrão, usando dados de exemplo...");
            const lectureData = getDefaultLectureData(lectureId);
            console.log("📋 Dados obtidos:", lectureData);
            populateLectureForm(lectureData);
            document.getElementById('lectureModal').style.display = 'flex';
            console.log("✅ Modal aberto para palestra padrão");
            return;
        }
        
        console.log("🌐 Fazendo requisição fetch...");
        const url = `manage_announcements.php?id=${lectureId}`;
        console.log("🔗 URL:", url);
        
        fetch(url)
            .then(response => {
                console.log("📡 Resposta recebida:", response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log("📊 Dados recebidos:", data);
                populateLectureForm(data);
                document.getElementById('lectureModal').style.display = 'flex';
                console.log("✅ Modal aberto com sucesso!");
            })
            .catch(error => {
                console.error("❌ Erro capturado:", error);
                alert(`Erro: ${error.message}`);
            });
            
    } catch (error) {
        console.error("💥 Erro crítico:", error);
        alert(`Erro crítico: ${error.message}`);
    }
}

// 4. Testar com um ID específico
console.log("🎯 Para testar, execute: testEditLecture('115faa0d55024b9b9670b82c4c7f9ad4')");

// 5. Verificar eventos de click nos botões
console.log("🖱️ Verificando botões de edição:");
const editButtons = document.querySelectorAll('[onclick*="editLecture"]');
console.log(`- Encontrados ${editButtons.length} botões de edição`);
editButtons.forEach((btn, index) => {
    console.log(`  Botão ${index + 1}:`, btn.getAttribute('onclick'));
});

// 6. Função para interceptar erros JavaScript
window.addEventListener('error', function(e) {
    console.error('🚨 Erro JavaScript interceptado:', {
        message: e.message,
        filename: e.filename,
        line: e.lineno,
        column: e.colno,
        error: e.error
    });
});

console.log("✅ Debug JavaScript configurado!");