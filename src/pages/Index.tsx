
import { useEffect } from "react";
import Header from "@/components/Header";
import ObituaryList from "@/components/ObituaryList";

const Index = () => {
  useEffect(() => {
    document.title = "Ontario Obituaries | Monaco Monuments";
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      
      <main className="flex-grow pt-32 pb-16">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-8">
          <div className="bg-card rounded-lg shadow-sm border border-border p-6">
            <h2 className="text-2xl font-semibold mb-3">Comprehensive Ontario Obituary Database</h2>
            <p className="text-muted-foreground mb-2">
              Our database is updated daily with obituaries from across Ontario, sourced from:
            </p>
            <ul className="grid grid-cols-1 md:grid-cols-2 gap-2 mb-4 ml-6 list-disc text-muted-foreground">
              <li>Local Ontario Funeral Homes</li>
              <li>Legacy.com - North America's largest provider of obituaries</li>
              <li>Obituary.com - Nationwide obituary collection</li>
              <li>Regional newspaper announcements</li>
            </ul>
            <p className="text-sm text-muted-foreground italic">
              Last updated: {new Date().toLocaleDateString()}
            </p>
          </div>
        </div>
        <ObituaryList />
      </main>
      
      <footer className="bg-secondary/30 border-t border-border/20 py-8">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex flex-col md:flex-row justify-between items-center gap-4">
            <div className="text-center md:text-left">
              <p className="text-sm text-muted-foreground">
                © {new Date().getFullYear()} Monaco Monuments. All rights reserved.
              </p>
              <p className="text-xs text-muted-foreground mt-1">
                Ontario Obituaries is updated daily from verified sources.
              </p>
            </div>
            <div className="flex items-center space-x-4">
              <a 
                href="https://monacomonuments.ca" 
                target="_blank" 
                rel="noopener noreferrer"
                className="text-sm text-muted-foreground hover:text-foreground transition-colors"
              >
                Visit Main Site
              </a>
              <span className="text-muted-foreground/30">|</span>
              <a 
                href="/settings" 
                className="text-sm text-muted-foreground hover:text-foreground transition-colors"
              >
                Admin
              </a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default Index;
